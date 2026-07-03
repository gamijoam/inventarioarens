<?php

namespace Tests\Feature\Warranties;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warranties\Models\WarrantyClaim;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WarrantyPolicyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_update_and_deactivate_warranty_policy(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Warranty Manager', [
            'warranty_policies.view',
            'warranty_policies.manage',
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warranty-policies', [
                'name' => 'Android 30 dias',
                'duration_days' => 30,
                'coverage_type' => WarrantyPolicy::COVERAGE_STORE,
                'conditions' => 'Cubre defectos de fabrica.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Android 30 dias')
            ->assertJsonPath('data.duration_days', 30)
            ->json('data');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/warranty-policies/{$response['id']}", [
                'name' => 'Android 45 dias',
                'duration_days' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Android 45 dias')
            ->assertJsonPath('data.duration_days', 45);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/warranty-policies/{$response['id']}")
            ->assertNoContent();

        $this->assertDatabaseHas('warranty_policies', [
            'tenant_id' => $tenant->id,
            'id' => $response['id'],
            'is_active' => false,
        ]);
    }

    public function test_warranty_policies_do_not_mix_companies(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $policyA = $this->policy($tenantA, 'Android 30 dias', 30);
        $this->policy($tenantB, 'iPhone 15 dias', 15);
        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Warranty Viewer', ['warranty_policies.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/warranty-policies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Android 30 dias');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/warranty-policies/{$policyA->id}")
            ->assertOk();
    }

    public function test_product_can_be_assigned_warranty_policy_from_current_company(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $policyA = $this->policy($tenantA, 'Android 30 dias', 30);
        $policyB = $this->policy($tenantB, 'iPhone 15 dias', 15);
        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Catalog Manager', ['products.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/products', [
                'name' => 'Samsung A06',
                'sku' => 'SAMSUNG-A06',
                'warranty_policy_id' => $policyA->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.warranty_policy_id', $policyA->id)
            ->assertJsonPath('data.warranty_policy.name', 'Android 30 dias');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/products', [
                'name' => 'iPhone 13',
                'sku' => 'IPHONE-13',
                'warranty_policy_id' => $policyB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['warranty_policy_id']);
    }

    public function test_sale_item_copies_warranty_snapshot_and_dates_on_confirmation(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $policy = $this->policy($tenant, 'Android 30 dias', 30, 'Cubre defectos.');
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, $policy);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Sales Manager', ['sales.create', 'sales.view']);
        $this->useTenant($tenant);

        app(InventoryMovementService::class)->purchase($warehouse, $product, 2, 80, $user, 'Stock garantia test');

        $sale = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.warranty_policy_id', $policy->id)
            ->assertJsonPath('data.items.0.warranty_policy_name', 'Android 30 dias')
            ->assertJsonPath('data.items.0.warranty_duration_days', 30)
            ->assertJsonPath('data.items.0.warranty_conditions', 'Cubre defectos.')
            ->assertJsonPath('data.items.0.warranty_starts_at', null)
            ->json('data');

        $confirmed = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/sales/{$sale['id']}/confirm")
            ->assertOk()
            ->json('data');

        $item = $confirmed['items'][0];

        $this->assertSame('Android 30 dias', $item['warranty_policy_name']);
        $this->assertNotNull($item['warranty_starts_at']);
        $this->assertNotNull($item['warranty_expires_at']);
        $this->assertEquals(30, now()->parse($item['warranty_starts_at'])->diffInDays(now()->parse($item['warranty_expires_at'])));

        $policy->update(['duration_days' => 15, 'name' => 'Android 15 dias']);

        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'sale_id' => $sale['id'],
            'warranty_policy_name' => 'Android 30 dias',
            'warranty_duration_days' => 30,
        ]);
    }

    public function test_warranty_policy_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'No Warranty Access', ['products.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/warranty-policies')
            ->assertForbidden();
    }

    public function test_user_can_create_warranty_claim_from_confirmed_sale_item(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $policy = $this->policy($tenant, 'Android 30 dias', 30);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, $policy);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Warranty Operator', ['sales.create', 'warranties.create', 'warranties.view']);
        $saleItem = $this->confirmedSaleItem($tenant, $user, $warehouse, $product);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warranty-claims', [
                'sale_item_id' => $saleItem->id,
                'quantity' => 1,
                'customer_name' => 'Cliente garantia',
                'customer_phone' => '04120000000',
                'issue_description' => 'Equipo no enciende.',
                'received_notes' => 'Sin golpes visibles.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', WarrantyClaim::STATUS_RECEIVED)
            ->assertJsonPath('data.sale_item_id', $saleItem->id)
            ->assertJsonPath('data.warranty_policy_name', 'Android 30 dias');

        $this->assertDatabaseHas('warranty_claims', [
            'tenant_id' => $tenant->id,
            'sale_item_id' => $saleItem->id,
            'status' => WarrantyClaim::STATUS_RECEIVED,
            'issue_description' => 'Equipo no enciende.',
        ]);
    }

    public function test_warranty_claim_rejects_expired_warranty(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $policy = $this->policy($tenant, 'Android 30 dias', 30);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, $policy);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Warranty Operator', ['sales.create', 'warranties.create']);
        $saleItem = $this->confirmedSaleItem($tenant, $user, $warehouse, $product);
        $saleItem->update(['warranty_expires_at' => now()->subDay()]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warranty-claims', [
                'sale_item_id' => $saleItem->id,
                'quantity' => 1,
                'issue_description' => 'Falla fuera de periodo.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sale_item_id']);
    }

    public function test_serialized_warranty_claim_places_unit_on_warranty_hold(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $policy = $this->policy($tenant, 'Android 30 dias', 30);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, $policy, Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Warranty Operator', ['sales.create', 'warranties.create']);
        $saleItem = $this->confirmedSaleItem($tenant, $user, $warehouse, $product);
        $this->useTenant($tenant);
        $unit = ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860001999999999',
            'status' => ProductUnit::STATUS_SOLD,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warranty-claims', [
                'sale_item_id' => $saleItem->id,
                'product_unit_id' => $unit->id,
                'quantity' => 1,
                'issue_description' => 'Pantalla con falla.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.product_unit_id', $unit->id)
            ->assertJsonPath('data.product_unit_serial', '860001999999999');

        $this->assertDatabaseHas('product_units', [
            'id' => $unit->id,
            'status' => ProductUnit::STATUS_WARRANTY_HOLD,
        ]);
    }

    public function test_user_can_review_and_deliver_warranty_claim_with_audit(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $policy = $this->policy($tenant, 'Android 30 dias', 30);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, $policy);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Warranty Manager', [
            'sales.create',
            'warranties.create',
            'warranties.review',
            'warranties.deliver',
            'warranties.view',
        ]);
        $saleItem = $this->confirmedSaleItem($tenant, $user, $warehouse, $product);

        $claim = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warranty-claims', [
                'sale_item_id' => $saleItem->id,
                'quantity' => 1,
                'issue_description' => 'No carga.',
            ])
            ->assertCreated()
            ->json('data');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/warranty-claims/{$claim['id']}/review", [
                'status' => WarrantyClaim::STATUS_APPROVED,
                'diagnosis' => 'Puerto de carga defectuoso.',
                'resolution_type' => WarrantyClaim::RESOLUTION_REPAIR,
                'resolution_notes' => 'Se aprueba reparacion.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WarrantyClaim::STATUS_APPROVED)
            ->assertJsonPath('data.resolution_type', WarrantyClaim::RESOLUTION_REPAIR);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/warranty-claims/{$claim['id']}/deliver", [
                'resolution_notes' => 'Equipo entregado al cliente.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WarrantyClaim::STATUS_DELIVERED);

        $this->useTenant($tenant);
        $this->assertSame(3, AuditLog::query()->where('entity_type', WarrantyClaim::class)->count());
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function policy(Tenant $tenant, string $name, int $days, ?string $conditions = null): WarrantyPolicy
    {
        $this->useTenant($tenant);

        return WarrantyPolicy::create([
            'name' => $name,
            'duration_days' => $days,
            'coverage_type' => WarrantyPolicy::COVERAGE_STORE,
            'conditions' => $conditions,
        ]);
    }

    private function warehouseAndProduct(Tenant $tenant, WarrantyPolicy $policy, string $trackingType = Product::TRACKING_QUANTITY): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen Principal', 'code' => 'MAIN-01']);
        $product = Product::create([
            'name' => 'Samsung A06',
            'sku' => 'SAMSUNG-A06',
            'tracking_type' => $trackingType,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
            'warranty_policy_id' => $policy->id,
        ]);

        return [$warehouse, $product];
    }

    private function confirmedSaleItem(Tenant $tenant, User $user, Warehouse $warehouse, Product $product): SaleItem
    {
        $this->useTenant($tenant);
        app(InventoryMovementService::class)->purchase($warehouse, $product, 2, 80, $user, 'Stock garantia claim test '.uniqid());

        $sale = app(SaleService::class)->createDraft($user, [[
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]]);

        $sale = app(SaleService::class)->confirm($sale, $user);

        return $sale->items()->firstOrFail();
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): Role
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $role;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
