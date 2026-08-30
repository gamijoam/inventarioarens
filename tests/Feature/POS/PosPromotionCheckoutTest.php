<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Fiscal\Models\FiscalTaxRate;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Sync\Models\SyncOutbox;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PosPromotionCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach ([
            'promotions.view',
            'promotions.create',
            'promotions.update',
            'pos.promotions.view',
            'pos.promotions.apply',
            'pos.promotions.code',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_checkout_applies_selected_bundle_price_and_persists_promotion_snapshot(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 50, 'COMBO-50');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(50)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(50.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertSame(PosOrder::STATUS_PAID, $response->json('data.status'));
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'promotion_id' => $promotion['id'],
            'promotion_code' => 'COMBO-50',
        ]);
        $event = SyncOutbox::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'pos.order.paid')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('COMBO-50', collect($event->payload['items'])->first()['promotion_code']);
    }

    public function test_combo_calculates_tax_per_component_after_allocating_bundle_discount(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $this->useTenant($tenant);
        $taxable = FiscalTaxRate::create([
            'code' => 'IVA16',
            'name' => 'IVA general',
            'rate' => 16,
            'category' => FiscalTaxRate::CATEGORY_TAXABLE,
            'is_active' => true,
        ]);
        $exempt = FiscalTaxRate::create([
            'code' => 'EXENTO',
            'name' => 'Exento',
            'rate' => 0,
            'category' => FiscalTaxRate::CATEGORY_EXEMPT,
            'is_active' => true,
        ]);
        $phone->update(['fiscal_tax_rate_id' => $taxable->id]);
        $charger->update(['fiscal_tax_rate_id' => $exempt->id]);
        $promotion = $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 50, 'COMBO-FISCAL');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(55.8182)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(
            50.0,
            (float) $response->json('data.sale.items.0.base_total_amount')
                + (float) $response->json('data.sale.items.1.base_total_amount'),
        );
        $this->assertSame(5.8182, (float) $response->json('data.sale.fiscal_tax_base_amount'));
        $this->assertSame(55.8182, (float) $response->json('data.sale.total_base_amount'));
        $this->assertSame(5.8182, (float) $response->json('data.sale.items.0.fiscal_tax_base_amount'));
        $this->assertSame('IVA16', $response->json('data.sale.items.0.fiscal_tax_code'));
        $this->assertSame('EXENTO', $response->json('data.sale.items.1.fiscal_tax_code'));
    }

    public function test_pending_order_recalculates_iva_before_payment_and_freezes_it_on_confirmation(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone] = $this->posFixture();
        $this->useTenant($tenant);
        $taxRate = FiscalTaxRate::create([
            'code' => 'IVA16',
            'name' => 'IVA general',
            'rate' => 16,
            'category' => FiscalTaxRate::CATEGORY_TAXABLE,
            'is_active' => true,
        ]);
        $phone->update(['fiscal_tax_rate_id' => $taxRate->id]);

        $pending = $this->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $phone->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $taxRate->update(['rate' => 15]);
        $this->useTenant($tenant);

        $response = $this->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders/'.$pending->json('data.id').'/payments', [
                'cash_register_session_id' => $session->id,
                'payments' => [$this->cashPayment(46)],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.total_base_amount', '46.0000')
            ->assertJsonPath('data.sale.items.0.fiscal_tax_rate', 15)
            ->assertJsonPath('data.sale.fiscal_tax_base_amount', 6)
            ->assertJsonPath('data.sale.fiscal_snapshot_at', fn (mixed $value): bool => $value !== null);

        $event = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'pos.order.paid')
            ->latest('id')
            ->first();
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame('6.0000', $payload['fiscal_tax_base_amount']);
        $this->assertSame('15.0000', $payload['items'][0]['fiscal_tax_rate']);
    }

    public function test_combo_can_override_component_tax_treatment_explicitly(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $this->useTenant($tenant);
        $taxable = FiscalTaxRate::create([
            'code' => 'IVA16',
            'name' => 'IVA general',
            'rate' => 16,
            'category' => FiscalTaxRate::CATEGORY_TAXABLE,
            'is_active' => true,
        ]);
        $exempt = FiscalTaxRate::create([
            'code' => 'EXENTO',
            'name' => 'Exento',
            'rate' => 0,
            'category' => FiscalTaxRate::CATEGORY_EXEMPT,
            'is_active' => true,
        ]);
        $phone->update(['fiscal_tax_rate_id' => $taxable->id]);
        $charger->update(['fiscal_tax_rate_id' => $taxable->id]);
        $promotion = $this->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Combo exento',
                'code' => 'COMBO-EXENTO',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'fiscal_tax_mode' => 'override',
                'fiscal_tax_rate_id' => $exempt->id,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(Promotion::FISCAL_TAX_MODE_OVERRIDE, $promotion['fiscal_tax_mode']);
        $this->assertSame($exempt->id, $promotion['fiscal_tax_rate_id']);
        $promotionEvent = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'promotion.created')
            ->latest('id')
            ->first();
        $this->assertSame(Promotion::FISCAL_TAX_MODE_OVERRIDE, json_decode((string) $promotionEvent->payload, true)['fiscal_tax_mode']);
        $this->assertSame($exempt->code, json_decode((string) $promotionEvent->payload, true)['fiscal_tax_rate_code']);

        $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(50)],
            'cash_register_session_id' => $session->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.sale.total_base_amount', 50)
            ->assertJsonPath('data.sale.fiscal_tax_base_amount', 0)
            ->assertJsonPath('data.sale.items.0.fiscal_tax_category', FiscalTaxRate::CATEGORY_EXEMPT)
            ->assertJsonPath('data.sale.items.1.fiscal_tax_category', FiscalTaxRate::CATEGORY_EXEMPT);
    }

    public function test_selected_combo_application_preserves_fiscal_override_on_checkout(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $this->useTenant($tenant);
        $exempt = FiscalTaxRate::create([
            'code' => 'EXENTO-SELECTED',
            'name' => 'Exento seleccionado',
            'rate' => 0,
            'category' => FiscalTaxRate::CATEGORY_EXEMPT,
            'is_active' => true,
        ]);
        $promotion = $this->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Combo seleccionado exento',
                'code' => 'COMBO-SELECTED-EXENTO',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'fiscal_tax_mode' => 'override',
                'fiscal_tax_rate_id' => $exempt->id,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->checkout($tenant, $cashier, [
            'cash_register_session_id' => $session->id,
            'combo_applications' => [[
                'promotion_id' => $promotion['id'],
                'instance_uuid' => 'selected-combo-1',
                'sets' => 1,
            ]],
            'items' => [
                ...array_map(
                    fn (array $item): array => [...$item, 'combo_instance_uuid' => 'selected-combo-1'],
                    $this->bundleItems($warehouse, $phone, $charger),
                ),
            ],
            'payments' => [$this->cashPayment(50)],
        ])
            ->assertCreated()
            ->assertJsonPath('data.sale.fiscal_tax_base_amount', 0)
            ->assertJsonPath('data.sale.fiscal_snapshot_at', fn (mixed $value): bool => $value !== null)
            ->assertJsonPath('data.sale.items.0.fiscal_tax_category', FiscalTaxRate::CATEGORY_EXEMPT)
            ->assertJsonPath('data.sale.items.1.fiscal_tax_category', FiscalTaxRate::CATEGORY_EXEMPT);
    }

    public function test_checkout_applies_percentage_discount_only_to_selected_products(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createPercentagePromotion($tenant, $cashier, $phone, 25, 'PHONE-25');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(45)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(45.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $phone->id,
            'promotion_id' => $promotion['id'],
            'promotion_discount_percent' => '25.00',
            'promotion_adjustment_base_amount' => '-10.0000',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $charger->id,
            'promotion_id' => null,
        ]);
        $event = SyncOutbox::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'pos.order.paid')
            ->latest('id')
            ->firstOrFail();
        $phoneEventItem = collect($event->payload['items'])->firstWhere('product_sku', $phone->sku);
        $this->assertSame(25.0, (float) $phoneEventItem['promotion_discount_percent']);
    }

    public function test_checkout_applies_invoice_percentage_discount_to_every_line_without_components(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Descuento toda la factura',
                'code' => 'INVOICE-25',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 25,
                'priority' => 20,
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('data');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(41.25)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(41.25, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $phone->id,
            'promotion_id' => $promotion['id'],
            'promotion_adjustment_base_amount' => '-10.0000',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $charger->id,
            'promotion_id' => $promotion['id'],
            'promotion_adjustment_base_amount' => '-3.7500',
        ]);
    }

    public function test_checkout_rejects_usd_payment_for_a_ves_only_promotion(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createPercentagePromotion($tenant, $cashier, $phone, 10, 'PHONE-VES');

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/promotions/'.$promotion['id'], ['payment_currency' => 'VES'])
            ->assertOk();

        $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(51)],
            'cash_register_session_id' => $session->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payments']);

        $this->assertDatabaseMissing('pos_orders', [
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $session->id,
        ]);
    }

    public function test_checkout_accepts_a_ves_only_promotion_when_the_full_payment_is_in_ves(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createPercentagePromotion($tenant, $cashier, $phone, 10, 'PHONE-VES-OK');

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/promotions/'.$promotion['id'], ['payment_currency' => 'VES'])
            ->assertOk();

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPaymentInCurrency(5050, Product::CURRENCY_VES)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(51.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertSame(Product::CURRENCY_VES, $response->json('data.payments.0.currency'));
    }

    public function test_pending_order_rejects_usd_payment_for_a_ves_only_promotion(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createPercentagePromotion($tenant, $cashier, $phone, 10, 'PENDING-VES');

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/promotions/'.$promotion['id'], ['payment_currency' => 'VES'])
            ->assertOk();

        $pending = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders', [
                'promotion_id' => $promotion['id'],
                'items' => $this->bundleItems($warehouse, $phone, $charger),
            ])
            ->assertCreated();

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders/'.$pending->json('data.id').'/payments', [
                'cash_register_session_id' => $session->id,
                'payments' => [$this->cashPayment(51)],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payments']);

        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenant->id,
            'id' => $pending->json('data.id'),
            'status' => PosOrder::STATUS_OPEN,
        ]);
    }

    public function test_checkout_distributes_fixed_discount_across_eligible_products(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createFixedDiscountPromotion($tenant, $cashier, $phone, $charger, 11, 'COMBO-11');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(44)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(44.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $phone->id,
            'promotion_id' => $promotion['id'],
            'promotion_discount_amount_usd' => '11.0000',
            'promotion_adjustment_base_amount' => '-8.0000',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $charger->id,
            'promotion_id' => $promotion['id'],
            'promotion_adjustment_base_amount' => '-3.0000',
        ]);
    }

    public function test_checkout_caps_fixed_discount_at_the_eligible_total(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createFixedDiscountPromotion($tenant, $cashier, $phone, $charger, 100, 'COMBO-FREE');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [array_merge($this->cashPayment(0.01), ['status' => PosPayment::STATUS_PENDING])],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(0.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'promotion_id' => $promotion['id'],
            'promotion_adjustment_base_amount' => '-40.0000',
        ]);
    }

    public function test_checkout_applies_fixed_item_price_per_unit_to_eligible_products(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createFixedItemPricePromotion($tenant, $cashier, $phone, 30, 'PHONE-30');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(45)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(45.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $phone->id,
            'promotion_id' => $promotion['id'],
            'promotion_price_usd' => '30.0000',
            'promotion_adjustment_base_amount' => '-10.0000',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $charger->id,
            'promotion_id' => null,
        ]);
    }

    public function test_checkout_makes_eligible_items_free_without_changing_stock_quantity(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createFreeItemPromotion($tenant, $cashier, $phone, 'FREE-PHONE');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(15)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(15.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $phone->id,
            'promotion_id' => $promotion['id'],
            'promotion_price_usd' => '0.0000',
            'promotion_adjustment_base_amount' => '-40.0000',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $charger->id,
            'promotion_id' => null,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $phone->id,
            'quantity_available' => 4,
        ]);
    }

    public function test_checkout_applies_buy_x_get_y_to_reward_items_and_supports_two_sets(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createBuyGetPromotion($tenant, $cashier, $phone, $charger);
        $items = $this->bundleItems($warehouse, $phone, $charger);
        $items[0]['quantity'] = 2;
        $items[1]['quantity'] = 2;

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $items,
            'payments' => [$this->cashPayment(80)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(80.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $phone->id,
            'promotion_id' => $promotion['id'],
            'quantity' => '2.0000',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $charger->id,
            'promotion_id' => $promotion['id'],
            'promotion_price_usd' => '0.0000',
            'promotion_adjustment_base_amount' => '-30.0000',
        ]);
    }

    public function test_checkout_rejects_buy_x_get_y_without_reward_in_cart(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createBuyGetPromotion($tenant, $cashier, $phone, $charger);

        $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => [[
                'warehouse_id' => $warehouse->id,
                'product_id' => $phone->id,
                'quantity' => 1,
            ]],
            'payments' => [$this->cashPayment(40)],
            'cash_register_session_id' => $session->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['promotion_id']);
    }

    public function test_checkout_supports_two_for_one_with_the_same_product_as_trigger_and_reward(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone] = array_slice($this->posFixture(), 0, 5);
        $promotion = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => '2x1 Telefono',
                'code' => 'PHONE-2X1',
                'benefit_type' => 'buy_x_get_y',
                'priority' => 10,
                'is_active' => true,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 2, 'item_role' => 'trigger'],
                    ['product_id' => $phone->id, 'quantity' => 1, 'item_role' => 'reward'],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => [[
                'warehouse_id' => $warehouse->id,
                'product_id' => $phone->id,
                'quantity' => 3,
            ]],
            'payments' => [$this->cashPayment(80)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(80.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $phone->id,
            'quantity' => '1.0000',
            'promotion_price_usd' => '0.0000',
            'promotion_adjustment_base_amount' => '-40.0000',
        ]);
    }

    public function test_checkout_allows_bundle_price_higher_than_normal_total(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 70, 'COMBO-70');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(70)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(70.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'promotion_id' => $promotion['id'],
            'promotion_code' => 'COMBO-70',
        ]);
    }

    public function test_checkout_can_apply_promotion_by_code_without_promotion_id(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 50, 'COMBO-CODE');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_code' => 'COMBO-CODE',
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(50)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(50.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'promotion_code' => 'COMBO-CODE',
        ]);
    }

    public function test_pending_order_keeps_promotion_price_after_promotion_expires(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 50, 'COMBO-PENDING');

        $pending = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(20)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/promotions/'.$promotion['id'], [
                'ends_at' => '2026-01-01 00:00:00',
            ])
            ->assertOk();

        $completed = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders/'.$pending->json('data.id').'/payments', [
                'payments' => [$this->cashPayment(30)],
            ])
            ->assertOk();

        $this->assertSame(50.0, (float) $completed->json('data.sale.total_base_amount'));
        $this->assertSame(PosOrder::STATUS_PAID, $completed->json('data.status'));
    }

    public function test_checkout_rejects_a_promotion_from_another_tenant(): void
    {
        [$tenantA, $adminA, , $warehouseA, $phoneA, $chargerA] = $this->posFixture();
        $promotion = $this->createBundlePromotion($tenantA, $adminA, $phoneA, $chargerA, 50, 'CROSS-TENANT');

        [$tenantB, $cashierB, $sessionB, $warehouseB, $phoneB, $chargerB] = $this->posFixture();

        $this->checkout($tenantB, $cashierB, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouseB, $phoneB, $chargerB),
            'payments' => [$this->cashPayment(50)],
            'cash_register_session_id' => $sessionB->id,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('pos_orders', [
            'tenant_id' => $tenantB->id,
            'cash_register_session_id' => $sessionB->id,
        ]);
    }

    public function test_checkout_rejects_promotion_without_apply_permission(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $promotion = $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 50, 'NO-APPLY');
        $cashier->revokePermissionTo('pos.promotions.apply');

        $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(50)],
            'cash_register_session_id' => $session->id,
        ])->assertForbidden();
    }

    public function test_checkout_without_promotion_permission_accepts_empty_promotion_arrays(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone] = $this->posFixture();
        $cashier->revokePermissionTo('pos.promotions.apply');

        $this->checkout($tenant, $cashier, [
            'cash_register_session_id' => $session->id,
            'combo_applications' => [],
            'product_offer_applications' => [],
            'items' => [[
                'warehouse_id' => $warehouse->id,
                'product_id' => $phone->id,
                'quantity' => 1,
            ]],
            'payments' => [$this->cashPayment(40)],
        ])->assertCreated()->assertJsonPath('data.status', PosOrder::STATUS_PAID);
    }

    public function test_request_permission_does_not_allow_validating_a_pending_invoice_promotion(): void
    {
        [$tenant, $seller, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $invoice = $this
            ->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/invoice-promotions', [
                'name' => 'Promocion con validacion separada',
                'code' => 'REQUEST-ONLY',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 10,
            ])
            ->assertCreated()
            ->json('data');
        $seller->revokePermissionTo('pos.promotions.apply');
        $seller->revokePermissionTo('pos.promotions.validate');

        $pending = $this
            ->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders', [
                'invoice_promotion_id' => $invoice['id'],
                'items' => $this->bundleItems($warehouse, $phone, $charger),
            ])
            ->assertCreated();

        $paymentPayload = [
            'cash_register_session_id' => $session->id,
            'invoice_promotion_action' => 'validate',
            'payments' => [$this->cashPayment(49.5)],
        ];
        $this
            ->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders/'.$pending->json('data.id').'/payments', $paymentPayload)
            ->assertForbidden();

        $seller->givePermissionTo('pos.promotions.validate');
        $this
            ->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders/'.$pending->json('data.id').'/payments', $paymentPayload)
            ->assertOk();
    }

    private function posFixture(): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa POS Promociones',
            'slug' => 'pos-promociones-'.str()->random(8),
        ]);
        $this->useTenant($tenant);

        $cashier = User::factory()->create();
        $cashier->tenants()->attach($tenant->id, ['status' => 'active']);
        $cashier->givePermissionTo([
            'promotions.view',
            'promotions.create',
            'promotions.update',
            'pos.promotions.apply',
            'pos.promotions.request',
            'pos.promotions.validate',
            'pos.promotions.code',
            'pos.orders.hold',
            'pos.checkout',
            'pos.view',
        ]);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-PROMO-'.str()->random(6)]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Almacen principal',
            'code' => 'WH-PROMO-'.str()->random(6),
        ]);
        $rateType = ExchangeRateType::create([
            'code' => 'BCV-PROMO',
            'name' => 'BCV Promociones',
            'is_default' => true,
        ]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => 100,
            'effective_at' => '2026-08-01 12:00:00',
            'is_active' => true,
        ]);
        $phone = Product::create([
            'name' => 'Telefono',
            'sku' => 'PHONE-PROMO-'.str()->random(6),
            'base_price' => 40,
            'sale_currency' => Product::CURRENCY_USD,
            'sale_exchange_rate_type_id' => $rateType->id,
        ]);
        $charger = Product::create([
            'name' => 'Cargador',
            'sku' => 'CHARGER-PROMO-'.str()->random(6),
            'base_price' => 15,
            'sale_currency' => Product::CURRENCY_USD,
            'sale_exchange_rate_type_id' => $rateType->id,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $phone->id,
            'quantity_available' => 5,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $charger->id,
            'quantity_available' => 5,
        ]);
        $cashRegister = CashRegister::create([
            'branch_id' => $branch->id,
            'name' => 'Caja promociones',
            'code' => 'CAJA-PROMO-'.str()->random(6),
            'status' => CashRegister::STATUS_ACTIVE,
        ]);
        $session = CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $cashRegister->id,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);

        return [$tenant, $cashier, $session, $warehouse, $phone, $charger];
    }

    private function createBundlePromotion(
        Tenant $tenant,
        User $admin,
        Product $phone,
        Product $charger,
        float $price,
        string $code,
    ): array {
        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Combo '.$code,
                'code' => $code,
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => $price,
                'priority' => 10,
                'is_active' => true,
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-12-31 23:59:59',
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        return $response->json('data');
    }

    private function createPercentagePromotion(
        Tenant $tenant,
        User $admin,
        Product $product,
        float $percent,
        string $code,
    ): array {
        return $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Descuento '.$code,
                'code' => $code,
                'benefit_type' => 'percent_discount',
                'discount_percent' => $percent,
                'priority' => 10,
                'is_active' => true,
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-12-31 23:59:59',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('data');
    }

    private function createFixedDiscountPromotion(
        Tenant $tenant,
        User $admin,
        Product $phone,
        Product $charger,
        float $amount,
        string $code,
    ): array {
        return $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Descuento '.$code,
                'code' => $code,
                'benefit_type' => 'fixed_discount',
                'discount_amount_usd' => $amount,
                'priority' => 10,
                'is_active' => true,
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-12-31 23:59:59',
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated()
            ->json('data');
    }

    private function createFixedItemPricePromotion(
        Tenant $tenant,
        User $admin,
        Product $product,
        float $price,
        string $code,
    ): array {
        return $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Precio fijo '.$code,
                'code' => $code,
                'benefit_type' => 'fixed_item_price',
                'price_usd' => $price,
                'priority' => 10,
                'is_active' => true,
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-12-31 23:59:59',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('data');
    }

    private function createFreeItemPromotion(
        Tenant $tenant,
        User $admin,
        Product $product,
        string $code,
    ): array {
        return $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Gratis '.$code,
                'code' => $code,
                'benefit_type' => 'free_item',
                'priority' => 10,
                'is_active' => true,
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-12-31 23:59:59',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('data');
    }

    private function createBuyGetPromotion(
        Tenant $tenant,
        User $admin,
        Product $phone,
        Product $charger,
    ): array {
        return $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Compra telefono recibe cargador',
                'code' => 'BUY-GET',
                'benefit_type' => 'buy_x_get_y',
                'priority' => 10,
                'is_active' => true,
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-12-31 23:59:59',
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1, 'item_role' => 'trigger'],
                    ['product_id' => $charger->id, 'quantity' => 1, 'item_role' => 'reward'],
                ],
            ])
            ->assertCreated()
            ->json('data');
    }

    private function bundleItems(Warehouse $warehouse, Product $phone, Product $charger): array
    {
        return [
            [
                'warehouse_id' => $warehouse->id,
                'product_id' => $phone->id,
                'quantity' => 1,
            ],
            [
                'warehouse_id' => $warehouse->id,
                'product_id' => $charger->id,
                'quantity' => 1,
            ],
        ];
    }

    private function cashPayment(float $amount): array
    {
        return $this->cashPaymentInCurrency($amount, Product::CURRENCY_USD);
    }

    private function cashPaymentInCurrency(float $amount, string $currency): array
    {
        return [
            'method' => PosPayment::METHOD_CASH,
            'currency' => $currency,
            'amount' => $amount,
        ];
    }

    private function checkout(Tenant $tenant, User $cashier, array $payload): TestResponse
    {
        return $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', $payload);
    }

    public function test_checkout_bundle_promotion_with_product_variant_preserves_color_and_price(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        // El telefono tiene variantes/colores.
        $green = $phone->variants()->create([
            'color' => 'Verde',
            'color_hex' => '#16A34A',
            'is_active' => true,
            'position' => 1,
        ]);
        // La variante verde tiene su propio stock en el almacen.
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $phone->id,
            'product_variant_id' => $green->id,
            'quantity_available' => 5,
        ]);
        $promotion = $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 50, 'COMBO-VAR');

        $response = $this->checkout($tenant, $cashier, [
            'promotion_id' => $promotion['id'],
            'items' => [
                [
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $phone->id,
                    'product_variant_id' => $green->id,
                    'quantity' => 1,
                ],
                [
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $charger->id,
                    'quantity' => 1,
                ],
            ],
            'payments' => [$this->cashPayment(50)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        // El total aplica el precio del bundle ($50) no la suma normal ($55).
        $this->assertSame(50.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertSame(PosOrder::STATUS_PAID, $response->json('data.status'));
        // La linea del telefono conserva la variante verde.
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $phone->id,
            'product_variant_id' => $green->id,
            'promotion_id' => $promotion['id'],
        ]);
    }

    public function test_checkout_applies_combo_before_invoice_promotion_and_persists_both_applications(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $combo = $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 50, 'COMBO-INVOICE');
        $invoice = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/invoice-promotions', [
                'name' => 'Diez por ciento factura',
                'code' => 'INVOICE-10',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 10,
                'allows_combos' => true,
            ])
            ->assertCreated()
            ->json('data');

        $items = $this->bundleItems($warehouse, $phone, $charger);
        foreach ($items as &$item) {
            $item['combo_instance_uuid'] = 'combo-instance-1';
        }
        unset($item);

        $response = $this->checkout($tenant, $cashier, [
            'invoice_promotion_id' => $invoice['id'],
            'combo_applications' => [[
                'promotion_id' => $combo['id'],
                'instance_uuid' => 'combo-instance-1',
                'sets' => 1,
            ]],
            'items' => $items,
            'payments' => [$this->cashPayment(45)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(45.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_promotion_applications', [
            'tenant_id' => $tenant->id,
            'sale_id' => $response->json('data.sale.id'),
            'promotion_id' => $combo['id'],
            'scope' => 'combo',
            'status' => 'validated',
        ]);
        $this->assertDatabaseHas('sale_promotion_applications', [
            'tenant_id' => $tenant->id,
            'sale_id' => $response->json('data.sale.id'),
            'promotion_id' => $invoice['id'],
            'scope' => 'invoice',
            'status' => 'validated',
            'base_before_amount' => '50.0000',
            'base_after_amount' => '45.0000',
        ]);
        $this->assertDatabaseCount('sale_promotion_application_items', 4);
        $this->assertSame('combo-instance-1', $response->json('data.sale.promotion_applications.0.instance_uuid'));
        $this->assertCount(2, $response->json('data.sale.promotion_applications.0.items'));
        $event = SyncOutbox::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'pos.order.paid')
            ->latest('id')
            ->firstOrFail();
        $this->assertCount(2, $event->payload['promotion_applications']);
        $this->assertSame('combo', $event->payload['promotion_applications'][0]['scope']);
        $this->assertNotNull($event->payload['promotion_applications'][0]['created_at']);
        $this->assertCount(2, $event->payload['promotion_applications'][0]['items']);
    }

    public function test_invoice_promotion_rejects_combo_when_combination_is_disabled(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $combo = $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 50, 'COMBO-BLOCKED');
        $invoice = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/invoice-promotions', [
                'name' => 'No combina',
                'code' => 'NO-COMBOS',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 10,
                'allows_combos' => false,
            ])
            ->assertCreated()
            ->json('data');
        $items = $this->bundleItems($warehouse, $phone, $charger);
        foreach ($items as &$item) {
            $item['combo_instance_uuid'] = 'blocked-instance';
        }
        unset($item);

        $this->checkout($tenant, $cashier, [
            'invoice_promotion_id' => $invoice['id'],
            'combo_applications' => [[
                'promotion_id' => $combo['id'],
                'instance_uuid' => 'blocked-instance',
                'sets' => 1,
            ]],
            'items' => $items,
            'payments' => [$this->cashPayment(45)],
            'cash_register_session_id' => $session->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice_promotion_id']);

        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_checkout_supports_multiple_combo_instances_plus_one_invoice_promotion(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $combo = $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 50, 'COMBO-MULTIPLE');
        $invoice = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/invoice-promotions', [
                'name' => 'Diez por ciento sobre combos',
                'code' => 'MULTIPLE-10',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 10,
                'allows_combos' => true,
            ])
            ->assertCreated()
            ->json('data');
        $items = [];
        foreach (['combo-a', 'combo-b'] as $instanceUuid) {
            foreach ($this->bundleItems($warehouse, $phone, $charger) as $item) {
                $items[] = array_merge($item, ['combo_instance_uuid' => $instanceUuid]);
            }
        }

        $response = $this->checkout($tenant, $cashier, [
            'invoice_promotion_id' => $invoice['id'],
            'combo_applications' => [
                ['promotion_id' => $combo['id'], 'instance_uuid' => 'combo-a', 'sets' => 1],
                ['promotion_id' => $combo['id'], 'instance_uuid' => 'combo-b', 'sets' => 1],
            ],
            'items' => $items,
            'payments' => [$this->cashPayment(90)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $this->assertSame(90.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseCount('sale_promotion_applications', 3);
        $this->assertDatabaseCount('sale_promotion_application_items', 8);
    }

    public function test_checkout_applies_product_offer_to_selected_line_with_multiple_combos_and_invoice_promotion(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $combo = $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 50, 'COMBO-WITH-OFFER');
        $offer = $this->createFixedItemPricePromotion($tenant, $cashier, $phone, 30, 'PHONE-OFFER-30');
        $invoice = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/invoice-promotions', [
                'name' => 'Diez por ciento con oferta',
                'code' => 'INVOICE-WITH-OFFER',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 10,
                'allows_combos' => true,
            ])
            ->assertCreated()
            ->json('data');

        $items = [];
        foreach (['combo-offer-a', 'combo-offer-b'] as $instanceUuid) {
            foreach ($this->bundleItems($warehouse, $phone, $charger) as $item) {
                $items[] = array_merge($item, ['combo_instance_uuid' => $instanceUuid]);
            }
        }
        $items[] = [
            'warehouse_id' => $warehouse->id,
            'product_id' => $phone->id,
            'quantity' => 1,
        ];

        $response = $this->checkout($tenant, $cashier, [
            'invoice_promotion_id' => $invoice['id'],
            'combo_applications' => [
                ['promotion_id' => $combo['id'], 'instance_uuid' => 'combo-offer-a', 'sets' => 1],
                ['promotion_id' => $combo['id'], 'instance_uuid' => 'combo-offer-b', 'sets' => 1],
            ],
            'product_offer_applications' => [[
                'promotion_id' => $offer['id'],
                'item_index' => 4,
            ]],
            'items' => $items,
            'payments' => [$this->cashPayment(117)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $saleId = $response->json('data.sale.id');
        $this->assertSame(117.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseCount('sale_promotion_applications', 4);
        $this->assertDatabaseCount('sale_promotion_application_items', 10);
        $this->assertDatabaseHas('sale_promotion_applications', [
            'tenant_id' => $tenant->id,
            'sale_id' => $saleId,
            'promotion_id' => $offer['id'],
            'slot' => 'product_offer:4',
            'scope' => 'product_offer',
            'base_before_amount' => '40.0000',
            'base_adjustment_amount' => '-10.0000',
            'base_after_amount' => '30.0000',
        ]);
        $this->assertDatabaseHas('sale_promotion_applications', [
            'tenant_id' => $tenant->id,
            'sale_id' => $saleId,
            'promotion_id' => $invoice['id'],
            'scope' => 'invoice',
            'base_before_amount' => '130.0000',
            'base_after_amount' => '117.0000',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'sale_id' => $saleId,
            'product_id' => $phone->id,
            'promotion_id' => $offer['id'],
            'promotion_price_usd' => '30.0000',
            'promotion_adjustment_base_amount' => '-13.0000',
        ]);
        $event = SyncOutbox::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'pos.order.paid')
            ->latest('id')
            ->firstOrFail();
        $productOfferApplication = collect($event->payload['promotion_applications'])
            ->firstWhere('scope', 'product_offer');
        $this->assertSame('product_offer:4', $productOfferApplication['slot']);
        $this->assertNotNull($productOfferApplication['created_at']);
        $this->assertCount(1, $productOfferApplication['items']);
    }

    public function test_product_offer_is_rejected_when_selected_item_belongs_to_a_combo_instance(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $combo = $this->createBundlePromotion($tenant, $cashier, $phone, $charger, 50, 'COMBO-OFFER-GUARD');
        $offer = $this->createFixedItemPricePromotion($tenant, $cashier, $phone, 30, 'PHONE-OFFER-GUARD');
        $items = $this->bundleItems($warehouse, $phone, $charger);
        foreach ($items as &$item) {
            $item['combo_instance_uuid'] = 'combo-offer-guard';
        }
        unset($item);

        $this->checkout($tenant, $cashier, [
            'combo_applications' => [[
                'promotion_id' => $combo['id'],
                'instance_uuid' => 'combo-offer-guard',
                'sets' => 1,
            ]],
            'product_offer_applications' => [[
                'promotion_id' => $offer['id'],
                'item_index' => 0,
            ]],
            'items' => $items,
            'payments' => [$this->cashPayment(50)],
            'cash_register_session_id' => $session->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['product_offer_applications']);

        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_product_offer_persists_historical_snapshot_and_sale_amounts(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $offer = $this->createFixedItemPricePromotion($tenant, $cashier, $phone, 30, 'PHONE-OFFER-SNAPSHOT');

        $response = $this->checkout($tenant, $cashier, [
            'product_offer_applications' => [[
                'promotion_id' => $offer['id'],
                'item_index' => 0,
            ]],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(45)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $saleId = $response->json('data.sale.id');
        $saleItemId = $response->json('data.sale.items.0.id');
        $this->assertDatabaseHas('sale_promotion_applications', [
            'sale_id' => $saleId,
            'promotion_id' => $offer['id'],
            'scope' => 'product_offer',
            'promotion_code' => 'PHONE-OFFER-SNAPSHOT',
            'benefit_type' => 'fixed_item_price',
            'price_usd' => '30.0000',
            'base_before_amount' => '40.0000',
            'base_adjustment_amount' => '-10.0000',
            'base_after_amount' => '30.0000',
        ]);
        $this->assertDatabaseHas('sale_promotion_application_items', [
            'sale_item_id' => $saleItemId,
            'quantity' => '1.0000',
            'base_before_amount' => '40.0000',
            'base_adjustment_amount' => '-10.0000',
            'base_after_amount' => '30.0000',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'id' => $saleItemId,
            'promotion_price_usd' => '30.0000',
            'unit_price' => '30.0000',
            'total_amount' => '30.0000',
            'base_total_amount' => '30.0000',
        ]);

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/promotions/'.$offer['id'], ['price_usd' => 5])
            ->assertOk();

        $this->assertDatabaseHas('sale_promotion_applications', [
            'sale_id' => $saleId,
            'promotion_id' => $offer['id'],
            'price_usd' => '30.0000',
            'base_after_amount' => '30.0000',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'id' => $saleItemId,
            'promotion_price_usd' => '30.0000',
            'total_amount' => '30.0000',
        ]);
    }

    public function test_checkout_applies_selected_free_product_offer_to_only_the_selected_line(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $offer = $this->createFreeItemPromotion($tenant, $cashier, $phone, 'PHONE-OFFER-FREE');

        $response = $this->checkout($tenant, $cashier, [
            'product_offer_applications' => [[
                'promotion_id' => $offer['id'],
                'item_index' => 0,
            ]],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'payments' => [$this->cashPayment(15)],
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $saleId = $response->json('data.sale.id');
        $this->assertSame(15.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertDatabaseHas('sale_promotion_applications', [
            'sale_id' => $saleId,
            'promotion_id' => $offer['id'],
            'scope' => 'product_offer',
            'base_before_amount' => '40.0000',
            'base_adjustment_amount' => '-40.0000',
            'base_after_amount' => '0.0000',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleId,
            'product_id' => $phone->id,
            'promotion_id' => $offer['id'],
            'promotion_price_usd' => '0.0000',
            'total_amount' => '0.0000',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleId,
            'product_id' => $charger->id,
            'promotion_id' => null,
        ]);
    }

    public function test_product_offer_application_item_index_is_validated_for_checkout_and_hold(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $offer = $this->createFixedItemPricePromotion($tenant, $cashier, $phone, 30, 'PHONE-OFFER-INDEX');
        $items = $this->bundleItems($warehouse, $phone, $charger);

        $payload = [
            'product_offer_applications' => [[
                'promotion_id' => $offer['id'],
                'item_index' => 2,
            ]],
            'items' => $items,
        ];

        $this->checkout($tenant, $cashier, array_merge($payload, [
            'payments' => [$this->cashPayment(55)],
            'cash_register_session_id' => $session->id,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['product_offer_applications.0.item_index']);

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_offer_applications.0.item_index']);

        $pending = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders', [
                'product_offer_applications' => [[
                    'promotion_id' => $offer['id'],
                    'item_index' => 0,
                ]],
                'items' => $items,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('sale_promotion_applications', [
            'sale_id' => $pending->json('data.sale.id'),
            'promotion_id' => $offer['id'],
            'scope' => 'product_offer',
        ]);
    }

    public function test_product_offer_application_rejects_a_promotion_from_another_tenant(): void
    {
        [$tenantA, $adminA, , $warehouseA, $phoneA, $chargerA] = $this->posFixture();
        $offer = $this->createFixedItemPricePromotion($tenantA, $adminA, $phoneA, 30, 'CROSS-TENANT-OFFER');

        [$tenantB, $cashierB, $sessionB, $warehouseB, $phoneB, $chargerB] = $this->posFixture();

        $this->checkout($tenantB, $cashierB, [
            'product_offer_applications' => [[
                'promotion_id' => $offer['id'],
                'item_index' => 0,
            ]],
            'items' => $this->bundleItems($warehouseB, $phoneB, $chargerB),
            'payments' => [$this->cashPayment(45)],
            'cash_register_session_id' => $sessionB->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['product_offer_applications.0.promotion_id']);

        $this->assertDatabaseMissing('pos_orders', [
            'tenant_id' => $tenantB->id,
            'cash_register_session_id' => $sessionB->id,
        ]);
    }

    public function test_seller_requests_ves_invoice_promotion_and_cashier_validates_it_when_paid_fully_in_ves(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $invoice = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/invoice-promotions', [
                'name' => 'Diez por ciento en bolivares',
                'code' => 'VES-INVOICE-10',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 10,
                'payment_currency' => 'VES',
                'allows_combos' => true,
            ])
            ->assertCreated()
            ->json('data');

        $pending = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders', [
                'invoice_promotion_id' => $invoice['id'],
                'items' => $this->bundleItems($warehouse, $phone, $charger),
            ])
            ->assertCreated();

        $this->assertSame(49.5, (float) $pending->json('data.total_base_amount'));
        $this->assertSame('requested', $pending->json('data.sale.promotion_applications.0.status'));
        $this->assertSame(55.0, (float) $pending->json('data.sale.promotion_applications.0.base_before_amount'));
        $this->assertSame(49.5, (float) $pending->json('data.sale.promotion_applications.0.base_after_amount'));
        $this->assertNotNull($pending->json('data.sale.promotion_applications.0.created_at'));
        $this->assertCount(2, $pending->json('data.sale.promotion_applications.0.items'));
        $this->assertDatabaseHas('sale_promotion_applications', [
            'tenant_id' => $tenant->id,
            'sale_id' => $pending->json('data.sale.id'),
            'promotion_id' => $invoice['id'],
            'scope' => 'invoice',
            'status' => 'requested',
            'requested_by' => $cashier->id,
        ]);

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders/'.$pending->json('data.id').'/payments', [
                'cash_register_session_id' => $session->id,
                'payments' => [$this->cashPaymentInCurrency(4950, Product::CURRENCY_VES)],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice_promotion_action']);

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/pos/orders?status=open')
            ->assertOk()
            ->assertJsonPath('data.0.sale.promotion_applications.0.status', 'requested');

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders/'.$pending->json('data.id').'/payments', [
                'cash_register_session_id' => $session->id,
                'invoice_promotion_action' => 'validate',
                'payments' => [$this->cashPaymentInCurrency(4950, Product::CURRENCY_VES)],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID);

        $this->assertDatabaseHas('sale_promotion_applications', [
            'sale_id' => $pending->json('data.sale.id'),
            'promotion_id' => $invoice['id'],
            'status' => 'validated',
            'validated_by' => $cashier->id,
        ]);
    }

    public function test_cashier_can_reject_requested_invoice_promotion_and_restore_total_before_payment(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $invoice = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/invoice-promotions', [
                'name' => 'Promocion removible',
                'code' => 'REMOVE-10',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 10,
            ])
            ->assertCreated()
            ->json('data');
        $pending = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders', [
                'invoice_promotion_id' => $invoice['id'],
                'items' => $this->bundleItems($warehouse, $phone, $charger),
            ])
            ->assertCreated();

        $response = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders/'.$pending->json('data.id').'/payments', [
                'cash_register_session_id' => $session->id,
                'invoice_promotion_action' => 'reject',
                'payments' => [$this->cashPayment(55)],
            ])
            ->assertOk();

        $this->assertSame(55.0, (float) $response->json('data.total_base_amount'));
        $this->assertSame(55.0, (float) $response->json('data.sale.total_base_amount'));
        $this->assertSame(5500.0, (float) $response->json('data.sale.total_local_amount'));
        $this->assertDatabaseHas('sale_promotion_applications', [
            'sale_id' => $pending->json('data.sale.id'),
            'promotion_id' => $invoice['id'],
            'status' => 'rejected',
            'validated_by' => $cashier->id,
        ]);
    }

    public function test_ves_invoice_promotion_rejects_credit_customer_credit_and_mixed_payments(): void
    {
        [$tenant, $cashier, $session, $warehouse, $phone, $charger] = $this->posFixture();
        $invoice = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/invoice-promotions', [
                'name' => 'Solo bolivares',
                'code' => 'VES-STRICT',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 10,
                'payment_currency' => 'VES',
            ])
            ->assertCreated()
            ->json('data');

        $base = [
            'invoice_promotion_id' => $invoice['id'],
            'items' => $this->bundleItems($warehouse, $phone, $charger),
            'cash_register_session_id' => $session->id,
        ];
        $this->useTenant($tenant);
        $customer = Customer::create([
            'name' => 'Cliente credito promocion',
            'document_type' => 'V',
            'document_number' => 'PROMO-'.str()->random(8),
        ]);

        $this->checkout($tenant, $cashier, array_merge($base, [
            'payments' => [
                $this->cashPaymentInCurrency(2475, Product::CURRENCY_VES),
                $this->cashPayment(24.75),
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['payments']);

        $this->checkout($tenant, $cashier, array_merge($base, [
            'payments' => [[
                'method' => PosPayment::METHOD_CUSTOMER_CREDIT,
                'currency' => Product::CURRENCY_VES,
                'amount' => 4950,
            ]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['payments']);

        $this->checkout($tenant, $cashier, array_merge($base, [
            'credit' => true,
            'customer_id' => $customer->id,
            'payments' => [],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['payments']);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
