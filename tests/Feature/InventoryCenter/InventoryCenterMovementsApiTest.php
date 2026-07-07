<?php

namespace Tests\Feature\InventoryCenter;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockMovement;
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

class InventoryCenterMovementsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_movements_endpoint_filters_paginates_and_includes_operational_context(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->useTenant($tenant);

        [$warehouseA, $warehouseB] = $this->warehouses();
        $product = Product::create([
            'name' => 'Cable USB Tipo C',
            'sku' => 'MOV-GLOBAL',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        StockMovement::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 5,
            'unit_cost' => 4,
            'reason' => 'Entrada inicial',
            'reference_type' => 'purchase',
            'reference_id' => 1,
            'created_by' => $user->id,
        ]);
        StockMovement::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $product->id,
            'type' => 'adjustment_out',
            'quantity' => 1,
            'unit_cost' => 4,
            'reason' => 'Ajuste conteo',
            'reference_type' => 'manual',
            'reference_id' => 2,
            'created_by' => $user->id,
        ]);
        StockMovement::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'quantity' => 2,
            'unit_cost' => 4,
            'reason' => 'Venta mostrador',
            'reference_type' => 'sale',
            'reference_id' => 3,
            'created_by' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/movements?search=cable&type=adjustment_out&warehouse_id={$warehouseA->id}&limit=1")
            ->assertOk()
            ->assertJsonPath('data.filters.type', 'adjustment_out')
            ->assertJsonPath('data.filters.warehouse_id', $warehouseA->id)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.product_name', 'Cable USB Tipo C')
            ->assertJsonPath('data.data.0.product_sku', 'MOV-GLOBAL')
            ->assertJsonPath('data.data.0.warehouse_name', 'Almacen Tienda')
            ->assertJsonPath('data.data.0.branch_name', 'Principal')
            ->assertJsonPath('data.data.0.reason', 'Ajuste conteo')
            ->assertJsonPath('data.data.0.created_by_name', $user->name);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/movements?limit=2&page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.has_next', true)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_global_movements_endpoint_is_tenant_isolated(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->inventoryUser($tenantA);

        $this->seedSingleMovement($tenantA, 'Producto Empresa A', 'MOV-A', 'Movimiento empresa A', $userA);
        $this->seedSingleMovement($tenantB, 'Producto Empresa B', 'MOV-B', 'Movimiento empresa B');

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/inventory-center/movements')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.product_name', 'Producto Empresa A')
            ->assertJsonMissing(['product_name' => 'Producto Empresa B']);
    }

    public function test_global_movements_endpoint_requires_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/movements')
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

    private function seedSingleMovement(
        Tenant $tenant,
        string $productName,
        string $sku,
        string $reason,
        ?User $user = null
    ): void {
        $this->useTenant($tenant);

        [$warehouse] = $this->warehouses();
        $product = Product::create([
            'name' => $productName,
            'sku' => $sku,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 1,
            'reason' => $reason,
            'reference_type' => 'test',
            'reference_id' => 1,
            'created_by' => $user?->id,
        ]);
    }

    private function warehouses(): array
    {
        $branch = Branch::create([
            'name' => 'Principal',
            'code' => 'BR-MOV',
        ]);

        return [
            Warehouse::create([
                'branch_id' => $branch->id,
                'name' => 'Almacen Tienda',
                'code' => 'WH-MOV-A',
            ]),
            Warehouse::create([
                'branch_id' => $branch->id,
                'name' => 'Almacen Deposito',
                'code' => 'WH-MOV-B',
            ]),
        ];
    }

    private function inventoryUser(Tenant $tenant, array $permissions = ['products.view', 'inventory.view']): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this->useTenant($tenant);
        $role = Role::findOrCreate('Inventario '.md5(implode('|', $permissions)), 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
