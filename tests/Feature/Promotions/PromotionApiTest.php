<?php

namespace Tests\Feature\Promotions;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Fiscal\Models\FiscalTaxRate;
use App\Modules\Products\Models\Product;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Sync\Models\SyncOutbox;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PromotionApiTest extends TestCase
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
            'promotions.delete',
            'pos.promotions.view',
            'pos.promotions.apply',
            'pos.promotions.code',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_administrator_can_create_bundle_with_arbitrary_usd_price(): void
    {
        [$tenant, $admin] = $this->tenantAndUser([
            'promotions.view',
            'promotions.create',
        ]);
        [$warehouse, $phone, $charger] = $this->bundleProducts($tenant);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Combo telefono + cargador',
                'code' => 'COMBO-50',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
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

        $this->assertSame('fixed_bundle_price', $response->json('data.benefit_type'));
        $this->assertSame(50.0, (float) $response->json('data.price_usd'));
        $this->assertSame('USD', $response->json('data.price_currency'));
        $this->assertSame(Promotion::FISCAL_TAX_MODE_INHERIT, $response->json('data.fiscal_tax_mode'));
        $this->assertCount(2, $response->json('data.items'));

        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'COMBO-50',
            'benefit_type' => 'fixed_bundle_price',
            'price_currency' => 'USD',
            'price_usd' => '50.0000',
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'promotion.created',
            'aggregate_type' => 'promotion',
            'aggregate_id' => $response->json('data.id'),
            'status' => 'pending',
        ]);

        $event = SyncOutbox::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'promotion.created')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('COMBO-50', $event->payload['code']);
        $this->assertSame(Promotion::SCOPE_COMBO, $event->payload['scope']);
        $this->assertCount(2, $event->payload['items']);
    }

    public function test_administrator_can_create_percentage_discount_for_selected_products(): void
    {
        [$tenant, $admin] = $this->tenantAndUser([
            'promotions.view',
            'promotions.create',
        ]);
        [, $phone] = $this->bundleProducts($tenant);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Descuento telefono',
                'code' => 'PHONE-25',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 25,
                'priority' => 20,
                'is_active' => true,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $this->assertSame('percent_discount', $response->json('data.benefit_type'));
        $this->assertSame(25.0, (float) $response->json('data.discount_percent'));
        $this->assertNull($response->json('data.price_usd'));
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'PHONE-25',
            'discount_percent' => '25.00',
        ]);

        $event = SyncOutbox::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'promotion.created')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(25.0, (float) $event->payload['discount_percent']);
    }

    public function test_administrator_can_create_invoice_percentage_discount_without_products(): void
    {
        [$tenant, $admin] = $this->tenantAndUser([
            'promotions.create',
        ]);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Descuento para toda la factura',
                'code' => 'INVOICE-25',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 25,
                'is_active' => true,
            ])
            ->assertCreated();

        $this->assertSame([], $response->json('data.items'));
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'INVOICE-25',
            'benefit_type' => 'percent_discount',
        ]);
        $event = SyncOutbox::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'promotion.created')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(Promotion::SCOPE_INVOICE, $event->payload['scope']);
        $this->assertFalse($event->payload['allows_combos']);
        $this->assertSame([], $event->payload['items']);
    }

    public function test_non_discount_promotions_still_require_components(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Combo sin componentes',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'items' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_combo_override_requires_a_tax_rate_from_the_same_tenant(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);
        [, $phone, $charger] = $this->bundleProducts($tenant);
        [$otherTenant] = $this->tenantAndUser([], 'other-tax-tenant');
        $this->useTenant($otherTenant);
        $otherRate = FiscalTaxRate::create([
            'code' => 'OTHER-EXEMPT',
            'name' => 'Exento otra empresa',
            'rate' => 0,
            'category' => FiscalTaxRate::CATEGORY_EXEMPT,
            'is_active' => true,
        ]);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/combos', [
                'name' => 'Combo con alicuota ajena',
                'benefit_type' => Promotion::BENEFIT_FIXED_BUNDLE_PRICE,
                'price_usd' => 50,
                'fiscal_tax_mode' => Promotion::FISCAL_TAX_MODE_OVERRIDE,
                'fiscal_tax_rate_id' => $otherRate->id,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiscal_tax_rate_id']);
    }

    public function test_administrator_can_restrict_a_promotion_to_ves_payments(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);
        [, $phone] = $this->bundleProducts($tenant);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Descuento solo VES',
                'code' => 'VES-10',
                'benefit_type' => 'percent_discount',
                'payment_currency' => 'VES',
                'discount_percent' => 10,
                'items' => [['product_id' => $phone->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->assertSame('VES', $response->json('data.payment_currency'));
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'VES-10',
            'payment_currency' => 'VES',
        ]);
        $event = SyncOutbox::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'promotion.created')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('VES', $event->payload['payment_currency']);
    }

    public function test_percentage_discount_must_be_between_zero_and_one_hundred(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);
        [, $phone] = $this->bundleProducts($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Descuento invalido',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 101,
                'items' => [['product_id' => $phone->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_percent']);
    }

    public function test_switching_an_existing_promotion_to_percentage_requires_the_percentage(): void
    {
        [$tenant, $admin] = $this->tenantAndUser([
            'promotions.create',
            'promotions.update',
        ]);
        [, $phone, $charger] = $this->bundleProducts($tenant);

        $created = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Combo convertible',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/promotions/'.$created->json('data.id'), [
                'benefit_type' => 'percent_discount',
                'items' => [['product_id' => $phone->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_percent']);
    }

    public function test_switching_a_combo_to_invoice_discount_removes_its_product_components(): void
    {
        [$tenant, $admin] = $this->tenantAndUser([
            'promotions.create',
            'promotions.update',
        ]);
        [, $phone, $charger] = $this->bundleProducts($tenant);

        $created = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Combo convertible a factura',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $updated = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/promotions/'.$created->json('data.id'), [
                'benefit_type' => 'percent_discount',
                'discount_percent' => 15,
            ])
            ->assertOk();

        $this->assertSame([], $updated->json('data.items'));
        $this->assertDatabaseCount('promotion_items', 0);
    }

    public function test_administrator_can_create_fixed_discount_for_selected_products(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);
        [, $phone] = $this->bundleProducts($tenant);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Descuento fijo telefono',
                'code' => 'PHONE-10',
                'benefit_type' => 'fixed_discount',
                'discount_amount_usd' => 10,
                'priority' => 15,
                'items' => [['product_id' => $phone->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->assertSame('fixed_discount', $response->json('data.benefit_type'));
        $this->assertSame(10.0, (float) $response->json('data.discount_amount_usd'));
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'PHONE-10',
            'discount_amount_usd' => '10.0000',
        ]);
    }

    public function test_fixed_discount_requires_a_positive_amount(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);
        [, $phone] = $this->bundleProducts($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Descuento fijo invalido',
                'benefit_type' => 'fixed_discount',
                'discount_amount_usd' => 0,
                'items' => [['product_id' => $phone->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_amount_usd']);
    }

    public function test_administrator_can_create_fixed_item_price_for_selected_products(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);
        [, $phone] = $this->bundleProducts($tenant);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Precio fijo telefono',
                'code' => 'PHONE-30',
                'benefit_type' => 'fixed_item_price',
                'price_usd' => 30,
                'priority' => 12,
                'items' => [['product_id' => $phone->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->assertSame('fixed_item_price', $response->json('data.benefit_type'));
        $this->assertSame(30.0, (float) $response->json('data.price_usd'));
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'PHONE-30',
            'price_usd' => '30.0000',
        ]);
    }

    public function test_administrator_can_create_free_item_promotion(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);
        [, $phone] = $this->bundleProducts($tenant);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Telefono gratis',
                'code' => 'FREE-PHONE',
                'benefit_type' => 'free_item',
                'priority' => 8,
                'items' => [['product_id' => $phone->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->assertSame('free_item', $response->json('data.benefit_type'));
        $this->assertNull($response->json('data.price_usd'));
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'FREE-PHONE',
            'benefit_type' => 'free_item',
        ]);
    }

    public function test_administrator_can_create_buy_x_get_y_with_trigger_and_reward_roles(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);
        [, $phone, $charger] = $this->bundleProducts($tenant);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Compra telefono recibe cargador',
                'code' => 'BUY-GET',
                'benefit_type' => 'buy_x_get_y',
                'priority' => 5,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1, 'item_role' => 'trigger'],
                    ['product_id' => $charger->id, 'quantity' => 1, 'item_role' => 'reward'],
                ],
            ])
            ->assertCreated();

        $this->assertSame('buy_x_get_y', $response->json('data.benefit_type'));
        $this->assertSame(['reward', 'trigger'], collect($response->json('data.items'))->pluck('item_role')->sort()->values()->all());
    }

    public function test_buy_x_get_y_requires_both_trigger_and_reward_components(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);
        [, $phone] = $this->bundleProducts($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Compra sin recompensa',
                'benefit_type' => 'buy_x_get_y',
                'items' => [['product_id' => $phone->id, 'quantity' => 1, 'item_role' => 'trigger']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_promotion_price_may_be_equal_or_higher_than_normal_bundle_total(): void
    {
        [$tenant, $admin] = $this->tenantAndUser([
            'promotions.view',
            'promotions.create',
        ]);
        [, $phone, $charger] = $this->bundleProducts($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Combo precio configurado',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 70,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.price_usd', fn (mixed $value): bool => (float) $value === 70.0);
    }

    public function test_promotion_creation_requires_promotion_permission(): void
    {
        [$tenant, $cashier] = $this->tenantAndUser(['pos.checkout']);
        [, $phone, $charger] = $this->bundleProducts($tenant);

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'No autorizado',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertForbidden();
    }

    public function test_bundle_requires_at_least_two_components(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);
        [, $phone] = $this->bundleProducts($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Combo incompleto',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'items' => [['product_id' => $phone->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_administrator_can_update_and_deactivate_promotion(): void
    {
        [$tenant, $admin] = $this->tenantAndUser([
            'promotions.view',
            'promotions.create',
            'promotions.update',
            'promotions.delete',
        ]);
        [, $phone, $charger] = $this->bundleProducts($tenant);

        $created = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/promotions', [
                'name' => 'Combo original',
                'code' => 'EDIT-ME',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();
        $promotionId = $created->json('data.id');

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/promotions/{$promotionId}", [
                'name' => 'Combo actualizado',
                'price_usd' => 70,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Combo actualizado')
            ->assertJsonPath('data.price_usd', fn (mixed $value): bool => (float) $value === 70.0);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/promotions/{$promotionId}")
            ->assertNoContent();

        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'id' => $promotionId,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'promotion.updated',
            'aggregate_id' => $promotionId,
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'promotion.deleted',
            'aggregate_id' => $promotionId,
        ]);
    }

    public function test_promotions_are_isolated_between_tenants(): void
    {
        [$tenantA, $adminA] = $this->tenantAndUser([
            'promotions.view',
            'promotions.create',
        ], 'tenant-a');
        [, $phone, $charger] = $this->bundleProducts($tenantA);

        $this
            ->actingAs($adminA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/promotions', [
                'name' => 'Privada de A',
                'code' => 'A-ONLY',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        [$tenantB, $adminB] = $this->tenantAndUser(['promotions.view'], 'tenant-b');

        $response = $this
            ->actingAs($adminB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/promotions')
            ->assertOk();

        $this->assertEmpty($response->json('data'));
        $this->assertNotSame($tenantA->id, $tenantB->id);
    }

    public function test_pos_lists_only_active_promotions_valid_for_the_current_date(): void
    {
        Carbon::setTestNow('2026-08-10 12:00:00');

        try {
            [$tenant, $admin] = $this->tenantAndUser([
                'promotions.view',
                'promotions.create',
                'pos.promotions.view',
            ]);
            [$warehouse, $phone, $charger] = $this->bundleProducts($tenant);

            $payload = fn (string $name, string $code, array $overrides = []): array => array_replace([
                'name' => $name,
                'code' => $code,
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'is_active' => true,
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-08-31 23:59:59',
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ], $overrides);

            $this
                ->actingAs($admin)
                ->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/promotions', $payload('Activa', 'ACTIVE'))
                ->assertCreated();

            $this
                ->actingAs($admin)
                ->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/promotions', $payload('Inactiva', 'INACTIVE', ['is_active' => false]))
                ->assertCreated();

            $this
                ->actingAs($admin)
                ->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/promotions', $payload('Vencida', 'EXPIRED', [
                    'starts_at' => '2026-07-01 00:00:00',
                    'ends_at' => '2026-08-09 23:59:59',
                ]))
                ->assertCreated();

            $response = $this
                ->actingAs($admin)
                ->withHeader('X-Tenant', $tenant->slug)
                ->getJson("/api/pos/promotions/available?warehouse_id={$warehouse->id}")
                ->assertOk();

            $codes = collect($response->json('data'))->pluck('code')->all();
            $this->assertSame(['ACTIVE'], $codes);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pos_does_not_stack_promotions_and_returns_the_highest_priority_match(): void
    {
        Carbon::setTestNow('2026-08-10 12:00:00');

        try {
            [$tenant, $admin] = $this->tenantAndUser([
                'promotions.view',
                'promotions.create',
                'pos.promotions.view',
            ]);
            [$warehouse, $phone, $charger] = $this->bundleProducts($tenant);
            $payload = [
                'benefit_type' => 'fixed_bundle_price',
                'is_active' => true,
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-08-31 23:59:59',
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ];

            $this
                ->actingAs($admin)
                ->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/promotions', array_replace($payload, [
                    'name' => 'Prioridad baja',
                    'code' => 'LOW-PRIORITY',
                    'price_usd' => 40,
                    'priority' => 10,
                ]))
                ->assertCreated();

            $this
                ->actingAs($admin)
                ->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/promotions', array_replace($payload, [
                    'name' => 'Prioridad alta',
                    'code' => 'HIGH-PRIORITY',
                    'price_usd' => 50,
                    'priority' => 100,
                ]))
                ->assertCreated();

            $response = $this
                ->actingAs($admin)
                ->withHeader('X-Tenant', $tenant->slug)
                ->getJson("/api/pos/promotions/available?warehouse_id={$warehouse->id}")
                ->assertOk();

            $this->assertSame(['HIGH-PRIORITY'], collect($response->json('data'))->pluck('code')->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pos_can_list_all_active_promotions_for_combo_selection_before_cart_items_exist(): void
    {
        Carbon::setTestNow('2026-08-10 12:00:00');

        try {
            [$tenant, $admin] = $this->tenantAndUser([
                'promotions.view',
                'promotions.create',
                'pos.promotions.view',
            ]);
            [$warehouse, $phone, $charger] = $this->bundleProducts($tenant);
            $payload = [
                'benefit_type' => 'fixed_bundle_price',
                'is_active' => true,
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-08-31 23:59:59',
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ];

            $this
                ->actingAs($admin)
                ->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/promotions', array_replace($payload, [
                    'name' => 'Combo uno',
                    'code' => 'COMBO-ONE',
                    'price_usd' => 40,
                    'priority' => 10,
                ]))
                ->assertCreated();

            $this
                ->actingAs($admin)
                ->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/promotions', array_replace($payload, [
                    'name' => 'Combo dos',
                    'code' => 'COMBO-TWO',
                    'price_usd' => 35,
                    'priority' => 20,
                ]))
                ->assertCreated();

            $response = $this
                ->actingAs($admin)
                ->withHeader('X-Tenant', $tenant->slug)
                ->getJson("/api/pos/promotions/available?warehouse_id={$warehouse->id}&selectable=1")
                ->assertOk();

            $this->assertSame(['COMBO-TWO', 'COMBO-ONE'], collect($response->json('data'))->pluck('code')->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_invoice_promotions_combos_and_product_offers_have_separate_endpoints(): void
    {
        [$tenant, $admin] = $this->tenantAndUser([
            'promotions.view',
            'promotions.create',
            'pos.promotions.view',
        ]);
        [$warehouse, $phone, $charger] = $this->bundleProducts($tenant);

        $invoicePromotion = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/invoice-promotions', [
                'name' => 'Diez por ciento pagando en VES',
                'code' => 'VES-10-INVOICE',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 10,
                'payment_currency' => 'VES',
                'allows_combos' => true,
            ])
            ->assertCreated();

        $combo = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/combos', [
                'name' => 'Telefono con cargador',
                'code' => 'PHONE-BUNDLE',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $offer = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-offers', [
                'name' => 'Telefono a precio especial',
                'code' => 'PHONE-OFFER',
                'benefit_type' => 'fixed_item_price',
                'price_usd' => 30,
                'items' => [['product_id' => $phone->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->assertSame('invoice', $invoicePromotion->json('data.scope'));
        $this->assertTrue($invoicePromotion->json('data.allows_combos'));
        $this->assertSame('combo', $combo->json('data.scope'));
        $this->assertSame('product_offer', $offer->json('data.scope'));

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/invoice-promotions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invoicePromotion->json('data.id'));

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/combos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $combo->json('data.id'));

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/product-offers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $offer->json('data.id'));

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/pos/combos?warehouse_id={$warehouse->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.scope', 'combo');
    }

    public function test_separate_endpoints_reject_benefit_types_from_another_domain(): void
    {
        [$tenant, $admin] = $this->tenantAndUser(['promotions.create']);
        [, $phone, $charger] = $this->bundleProducts($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/invoice-promotions', [
                'name' => 'No es una promocion de factura',
                'benefit_type' => 'fixed_bundle_price',
                'price_usd' => 50,
                'items' => [
                    ['product_id' => $phone->id, 'quantity' => 1],
                    ['product_id' => $charger->id, 'quantity' => 1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['benefit_type']);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/combos', [
                'name' => 'No es combo',
                'benefit_type' => 'percent_discount',
                'discount_percent' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['benefit_type']);
    }

    private function tenantAndUser(array $permissions, ?string $slug = null): array
    {
        $slug ??= 'tenant-'.str()->lower(str()->random(8));
        $tenant = Tenant::create(['name' => strtoupper($slug), 'slug' => $slug]);
        $this->useTenant($tenant);

        $user = User::factory()->create([
            'email' => "admin-{$slug}@test.test",
        ]);
        $user->tenants()->attach($tenant->id, ['status' => 'active']);
        $user->givePermissionTo($permissions);

        return [$tenant, $user];
    }

    private function bundleProducts(Tenant $tenant): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create([
            'name' => 'Principal',
            'code' => 'BR-'.strtoupper(substr(str()->uuid()->toString(), 0, 8)),
        ]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Almacen principal',
            'code' => 'WH-'.strtoupper(substr(str()->uuid()->toString(), 0, 8)),
        ]);
        $phone = Product::create([
            'name' => 'Telefono',
            'sku' => 'PHONE-'.strtoupper(substr(str()->uuid()->toString(), 0, 8)),
            'base_price' => 40,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $charger = Product::create([
            'name' => 'Cargador',
            'sku' => 'CHARGER-'.strtoupper(substr(str()->uuid()->toString(), 0, 8)),
            'base_price' => 15,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$warehouse, $phone, $charger];
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
