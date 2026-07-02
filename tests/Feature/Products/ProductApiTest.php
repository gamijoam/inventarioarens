<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_serialized_product_inside_current_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Telefonos Arens', 'slug' => 'telefonos-arens']);
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products', [
                'name' => 'Samsung A06',
                'sku' => 'SAMSUNG-A06',
                'tracking_type' => Product::TRACKING_SERIALIZED,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Samsung A06')
            ->assertJsonPath('data.sku', 'SAMSUNG-A06')
            ->assertJsonPath('data.tracking_type', Product::TRACKING_SERIALIZED)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('products', [
            'tenant_id' => $tenant->id,
            'sku' => 'SAMSUNG-A06',
            'tracking_type' => Product::TRACKING_SERIALIZED,
        ]);
    }

    public function test_products_index_does_not_mix_multiple_companies(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        $this->productFor($tenantA, 'Samsung A06', 'SAMSUNG-A06');
        $this->productFor($tenantB, 'Redmi A3', 'REDMI-A3');

        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Vendedor', ['products.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Samsung A06');
    }

    public function test_sku_is_unique_inside_tenant_but_can_repeat_between_tenants(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        $this->productFor($tenantB, 'Samsung A06', 'SAME-SKU');
        $this->productFor($tenantA, 'Samsung A06', 'SAME-SKU');

        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Catalog Manager', ['products.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/products', [
                'name' => 'Samsung A06 Nuevo',
                'sku' => 'SAME-SKU',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        $this->assertDatabaseCount('products', 2);
    }

    public function test_user_can_update_and_deactivate_product_inside_current_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $product = $this->productFor($tenant, 'Samsung A06', 'SAMSUNG-A06');
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.view', 'products.update', 'products.delete']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/products/{$product->id}", [
                'name' => 'Samsung A06 128GB',
                'tracking_type' => Product::TRACKING_SERIALIZED,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Samsung A06 128GB')
            ->assertJsonPath('data.tracking_type', Product::TRACKING_SERIALIZED);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/products/{$product->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'tenant_id' => $tenant->id,
            'is_active' => false,
        ]);
    }

    public function test_product_with_serialized_units_cannot_change_tracking_type(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH']);
        $product = Product::create([
            'name' => 'Samsung A06',
            'sku' => 'SAMSUNG-A06',
            'tracking_type' => Product::TRACKING_SERIALIZED,
        ]);
        ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-A06-001',
        ]);

        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.update']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/products/{$product->id}", [
                'tracking_type' => Product::TRACKING_QUANTITY,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tracking_type']);
    }

    public function test_product_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products', [
                'name' => 'Samsung A06',
                'sku' => 'SAMSUNG-A06',
            ])
            ->assertForbidden();
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function productFor(Tenant $tenant, string $name, string $sku): Product
    {
        $this->useTenant($tenant);

        return Product::create([
            'name' => $name,
            'sku' => $sku,
        ]);
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
