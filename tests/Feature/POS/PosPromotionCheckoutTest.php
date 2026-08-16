<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Models\SyncOutbox;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'pos.promotions.code',
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
        return [
            'method' => PosPayment::METHOD_CASH,
            'currency' => Product::CURRENCY_USD,
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

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
