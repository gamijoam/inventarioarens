<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\AuthorizedInventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_gates_allow_only_current_tenant_members_with_permission(): void
    {
        [$tenantA, $tenantB] = $this->tenants();
        [$warehouseA, $productA] = $this->warehouseAndProduct($tenantA, 'A');
        [$warehouseB, $productB] = $this->warehouseAndProduct($tenantB, 'B');
        $user = $this->userInTenant($tenantA);

        $this->grantRole($tenantA, $user, 'Almacen', ['inventory.view', 'inventory.adjust', 'inventory.transfer']);
        $this->useTenant($tenantA);

        $this->assertTrue(Gate::forUser($user)->allows('inventory.view-operation'));
        $this->assertTrue(Gate::forUser($user)->allows('inventory.adjust-operation', [$warehouseA, $productA]));
        $this->assertFalse(Gate::forUser($user)->allows('inventory.adjust-operation', [$warehouseB, $productB]));

        $this->useTenant($tenantB);

        $this->assertFalse(Gate::forUser($user)->allows('inventory.view-operation'));
        $this->assertFalse(Gate::forUser($user)->allows('inventory.adjust-operation', [$warehouseB, $productB]));
    }

    public function test_authorized_adjustment_updates_inventory_for_allowed_user(): void
    {
        [$tenant] = $this->tenants();
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'A');
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Almacen', ['inventory.adjust']);
        $this->useTenant($tenant);

        $movement = $this->authorizedService()->adjustmentIn($user, $warehouse, $product, 5, 'Conteo inicial');

        $this->assertSame('adjustment_in', $movement->type);
        $this->assertSame($user->id, $movement->created_by);
        $this->assertSame(5.0, (float) $this->balance($warehouse, $product)->quantity_available);
    }

    public function test_authorized_inventory_service_rejects_user_without_permission(): void
    {
        [$tenant] = $this->tenants();
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'A');
        $user = $this->userInTenant($tenant);

        $this->useTenant($tenant);

        $this->expectException(AuthorizationException::class);

        $this->authorizedService()->adjustmentIn($user, $warehouse, $product, 5);
    }

    public function test_purchase_and_sale_use_purchase_and_sale_permissions(): void
    {
        [$tenant] = $this->tenants();
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'A');
        $purchaseUser = $this->userInTenant($tenant);
        $salesUser = $this->userInTenant($tenant);

        $this->grantRole($tenant, $purchaseUser, 'Compras', ['purchases.create']);
        $this->grantRole($tenant, $salesUser, 'Ventas', ['sales.create']);
        $this->useTenant($tenant);

        $purchase = $this->authorizedService()->purchase($purchaseUser, $warehouse, $product, 10, 80);
        $sale = $this->authorizedService()->sale($salesUser, $warehouse, $product, 3);

        $this->assertSame('purchase', $purchase->type);
        $this->assertSame('sale', $sale->type);
        $this->assertSame(7.0, (float) $this->balance($warehouse, $product)->quantity_available);
    }

    public function test_transfer_requires_transfer_permission_and_same_tenant_resources(): void
    {
        [$tenantA, $tenantB] = $this->tenants();
        [$fromWarehouse, $productA] = $this->warehouseAndProduct($tenantA, 'A');
        [$toWarehouse] = $this->warehouseAndProduct($tenantA, 'A2');
        [$foreignWarehouse] = $this->warehouseAndProduct($tenantB, 'B');
        $user = $this->userInTenant($tenantA);

        $this->grantRole($tenantA, $user, 'Transferencias', ['inventory.adjust', 'inventory.transfer']);
        $this->useTenant($tenantA);

        $this->authorizedService()->adjustmentIn($user, $fromWarehouse, $productA, 8);
        $movements = $this->authorizedService()->transfer($user, $fromWarehouse, $toWarehouse, $productA, 3);

        $this->assertSame(['transfer_out', 'transfer_in'], [$movements[0]->type, $movements[1]->type]);
        $this->assertSame(5.0, (float) $this->balance($fromWarehouse, $productA)->quantity_available);
        $this->assertSame(3.0, (float) $this->balance($toWarehouse, $productA)->quantity_available);

        $this->expectException(AuthorizationException::class);

        $this->authorizedService()->transfer($user, $fromWarehouse, $foreignWarehouse, $productA, 1);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Permission::findOrCreate('purchases.create', 'web');
        Permission::findOrCreate('sales.create', 'web');
    }

    private function tenants(): array
    {
        return [
            Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']),
            Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']),
        ];
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

    private function authorizedService(): AuthorizedInventoryMovementService
    {
        return app(AuthorizedInventoryMovementService::class);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
