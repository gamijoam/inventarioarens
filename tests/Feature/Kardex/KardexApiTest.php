<?php

namespace Tests\Feature\Kardex;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class KardexApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_kardex_returns_opening_balance_running_balance_and_movements(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'A');
        $user = $this->kardexUser($tenant);

        $this->dated($this->service()->purchase($warehouse, $product, 10), '2026-07-01 09:00:00');
        $this->dated($this->service()->sale($warehouse, $product, 3), '2026-07-02 09:00:00');
        $this->dated($this->service()->saleReturn($warehouse, $product, 1, referenceType: SalesReturn::class, referenceId: 1), '2026-07-03 09:00:00');
        $this->dated($this->service()->adjustmentOut($warehouse, $product, 2), '2026-07-04 09:00:00');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/kardex/products/{$product->id}?date_from=2026-07-02&date_to=2026-07-04")
            ->assertOk()
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.opening_balance', 10)
            ->assertJsonPath('data.closing_balance', 6)
            ->assertJsonCount(3, 'data.movements')
            ->assertJsonPath('data.movements.0.type', 'sale')
            ->assertJsonPath('data.movements.0.quantity_in', 0)
            ->assertJsonPath('data.movements.0.quantity_out', 3)
            ->assertJsonPath('data.movements.0.running_balance', 7)
            ->assertJsonPath('data.movements.1.type', 'sale_return')
            ->assertJsonPath('data.movements.1.quantity_in', 1)
            ->assertJsonPath('data.movements.1.running_balance', 8)
            ->assertJsonPath('data.movements.2.type', 'adjustment_out')
            ->assertJsonPath('data.movements.2.running_balance', 6);
    }

    public function test_kardex_can_filter_by_warehouse(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouseA, $product] = $this->warehouseAndProduct($tenant, 'A');
        $warehouseB = $this->warehouse($tenant, 'B');
        $user = $this->kardexUser($tenant);

        $this->service()->purchase($warehouseA, $product, 10);
        $this->service()->purchase($warehouseB, $product, 5);
        $this->service()->sale($warehouseA, $product, 2);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/kardex/products/{$product->id}?warehouse_id={$warehouseA->id}")
            ->assertOk()
            ->assertJsonPath('data.warehouse_id', $warehouseA->id)
            ->assertJsonPath('data.closing_balance', 8)
            ->assertJsonCount(2, 'data.movements');
    }

    public function test_kardex_does_not_mix_companies_and_rejects_foreign_filters(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$warehouseA, $productA] = $this->warehouseAndProduct($tenantA, 'A');
        [$warehouseB, $productB] = $this->warehouseAndProduct($tenantB, 'B');
        $this->useTenant($tenantA);
        $this->service()->purchase($warehouseA, $productA, 10);
        $this->useTenant($tenantB);
        $this->service()->purchase($warehouseB, $productB, 5);
        $user = $this->kardexUser($tenantA);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/kardex/products/{$productA->id}")
            ->assertOk()
            ->assertJsonPath('data.product_name', 'Producto A')
            ->assertJsonPath('data.closing_balance', 10);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/kardex/products/{$productA->id}?warehouse_id={$warehouseB->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_kardex_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [, $product] = $this->warehouseAndProduct($tenant, 'A');
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/kardex/products/{$product->id}")
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

    private function warehouseAndProduct(Tenant $tenant, string $suffix): array
    {
        $this->useTenant($tenant);

        $warehouse = $this->warehouse($tenant, $suffix);
        $product = Product::create(['name' => "Producto {$suffix}", 'sku' => "SKU-KDX-{$suffix}"]);

        return [$warehouse, $product];
    }

    private function warehouse(Tenant $tenant, string $suffix): Warehouse
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$suffix}", 'code' => "BR-KDX-{$suffix}-{$tenant->id}"]);

        return Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$suffix}", 'code' => "WH-KDX-{$suffix}-{$tenant->id}"]);
    }

    private function kardexUser(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, 'Auditor Kardex', ['kardex.view']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function service(): InventoryMovementService
    {
        return app(InventoryMovementService::class);
    }

    private function dated(StockMovement $movement, string $date): void
    {
        $movement->forceFill([
            'created_at' => $date,
            'updated_at' => $date,
        ])->save();
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
