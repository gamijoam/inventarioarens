<?php

namespace Tests\Feature\Inventory;

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

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjustment_in_endpoint_requires_auth_tenant_and_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'A');
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Almacen', ['inventory.adjust']);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory/adjustments/in', [
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 6,
                'reason' => 'Conteo inicial',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.type', 'adjustment_in')
            ->assertJsonPath('data.quantity', 6)
            ->assertJsonPath('data.created_by', $user->id);

        $this->assertSame(6.0, (float) $this->balance($warehouse, $product)->quantity_available);
    }

    public function test_inventory_endpoint_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'A');
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory/adjustments/in', [
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 6,
            ])
            ->assertForbidden();
    }

    public function test_inventory_endpoint_rejects_resources_from_another_tenant_during_validation(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
        [$warehouseA] = $this->warehouseAndProduct($tenantA, 'A');
        [, $productB] = $this->warehouseAndProduct($tenantB, 'B');
        $user = $this->userInTenant($tenantA);

        $this->grantRole($tenantA, $user, 'Almacen', ['inventory.adjust']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/inventory/adjustments/in', [
                'warehouse_id' => $warehouseA->id,
                'product_id' => $productB->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);
    }

    public function test_transfer_endpoint_updates_both_warehouses(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $fromWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen', 'code' => 'FROM']);
        $toWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino', 'code' => 'TO']);
        $product = Product::create(['name' => 'Redmi A3', 'sku' => 'REDMI-A3']);
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Almacen', ['inventory.adjust', 'inventory.transfer']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory/adjustments/in', [
                'warehouse_id' => $fromWarehouse->id,
                'product_id' => $product->id,
                'quantity' => 10,
            ])
            ->assertCreated();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory/transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'product_id' => $product->id,
                'quantity' => 4,
                'reason' => 'Reposicion',
            ])
            ->assertOk()
            ->assertJsonPath('data.0.type', 'transfer_out')
            ->assertJsonPath('data.1.type', 'transfer_in');

        $this->assertSame(6.0, (float) $this->balance($fromWarehouse, $product)->quantity_available);
        $this->assertSame(4.0, (float) $this->balance($toWarehouse, $product)->quantity_available);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function warehouseAndProduct(Tenant $tenant, string $suffix): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$suffix}", 'code' => "BR-{$suffix}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$suffix}", 'code' => "WH-{$suffix}"]);
        $product = Product::create(['name' => "Producto {$suffix}", 'sku' => "SKU-{$suffix}"]);

        return [$warehouse, $product];
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

    private function balance(Warehouse $warehouse, Product $product): StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
