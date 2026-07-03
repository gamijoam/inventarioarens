<?php

namespace Tests\Feature\InventoryCenter;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
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

class InventoryCenterSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_center_returns_real_metrics_and_aggregated_products(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->seedInventory($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary?low_stock_threshold=3')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_products', 3)
            ->assertJsonPath('data.metrics.serialized_products', 1)
            ->assertJsonPath('data.metrics.quantity_products', 2)
            ->assertJsonPath('data.metrics.available_quantity', 17)
            ->assertJsonPath('data.metrics.reserved_quantity', 2)
            ->assertJsonPath('data.metrics.damaged_quantity', 1)
            ->assertJsonPath('data.metrics.low_stock_count', 1)
            ->assertJsonPath('data.metrics.without_stock_count', 1)
            ->assertJsonPath('data.products.0.name', 'Audifonos Tipo C')
            ->assertJsonPath('data.products.0.stock.available', 12)
            ->assertJsonPath('data.products.0.stock.status', 'available')
            ->assertJsonPath('data.products.1.name', 'Samsung A06')
            ->assertJsonPath('data.products.1.stock.available', 5)
            ->assertJsonPath('data.products.2.name', 'Xiaomi Serial')
            ->assertJsonPath('data.products.2.stock.status', 'out');
    }

    public function test_inventory_center_filters_by_search_and_stock_status(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->seedInventory($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary?search=IMEI&tracking_type=serialized&stock_status=out')
            ->assertOk()
            ->assertJsonPath('data.products.0.name', 'Xiaomi Serial')
            ->assertJsonCount(1, 'data.products');
    }

    public function test_inventory_center_does_not_mix_companies(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->inventoryUser($tenantA);

        $this->seedInventory($tenantA);
        $this->seedInventory($tenantB, 1000);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/inventory-center/summary')
            ->assertOk()
            ->assertJsonPath('data.metrics.available_quantity', 17)
            ->assertJsonMissing(['sku' => 'A06-1000']);
    }

    public function test_inventory_center_requires_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary')
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

    private function seedInventory(Tenant $tenant, int $offset = 0): void
    {
        $this->useTenant($tenant);

        $branch = Branch::create([
            'name' => "Principal {$offset}",
            'code' => "BR-{$offset}",
        ]);

        $warehouseA = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => "Almacen A {$offset}",
            'code' => "WH-A-{$offset}",
        ]);

        $warehouseB = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => "Almacen B {$offset}",
            'code' => "WH-B-{$offset}",
        ]);

        $samsung = Product::create([
            'name' => 'Samsung A06',
            'sku' => "A06-{$offset}",
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 120,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        $audifonos = Product::create([
            'name' => 'Audifonos Tipo C',
            'sku' => "AUD-{$offset}",
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 8,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        Product::create([
            'name' => 'Xiaomi Serial',
            'sku' => "IMEI-{$offset}",
            'tracking_type' => Product::TRACKING_SERIALIZED,
            'base_price' => 90,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $samsung->id,
            'quantity_available' => 2,
            'quantity_reserved' => 1,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $samsung->id,
            'quantity_available' => 3,
            'quantity_reserved' => 1,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $audifonos->id,
            'quantity_available' => 12,
            'quantity_damaged' => 1,
        ]);
    }

    private function inventoryUser(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, 'Inventario', ['products.view', 'inventory.view']);

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
