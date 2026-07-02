<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Services\InventoryMovementService;
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

class InventoryReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_report_does_not_mix_multiple_companies(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        [$warehouseA, $productA] = $this->warehouseAndProduct($tenantA, 'A');
        $this->service()->purchase($warehouseA, $productA, 10);
        $userA = $this->reportUser($tenantA);

        [$warehouseB, $productB] = $this->warehouseAndProduct($tenantB, 'B');
        $this->service()->purchase($warehouseB, $productB, 5);
        $userB = $this->reportUser($tenantB);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/reports/stock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_name', 'Producto A')
            ->assertJsonPath('data.0.quantity_available', 10);

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/reports/stock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_name', 'Producto B')
            ->assertJsonPath('data.0.quantity_available', 5);
    }

    public function test_low_stock_report_uses_threshold_inside_current_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouseA, $productA] = $this->warehouseAndProduct($tenant, 'A');
        [$warehouseB, $productB] = $this->warehouseAndProduct($tenant, 'B');
        $user = $this->reportUser($tenant);

        $this->service()->purchase($warehouseA, $productA, 10);
        $this->service()->purchase($warehouseB, $productB, 2);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/stock/low?threshold=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_name', 'Producto B')
            ->assertJsonPath('data.0.quantity_available', 2);
    }

    public function test_movements_report_filters_by_type_and_current_tenant(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        [$warehouseA, $productA] = $this->warehouseAndProduct($tenantA, 'A');
        $this->service()->purchase($warehouseA, $productA, 10);
        $this->service()->sale($warehouseA, $productA, 3);
        $userA = $this->reportUser($tenantA);

        [$warehouseB, $productB] = $this->warehouseAndProduct($tenantB, 'B');
        $this->service()->purchase($warehouseB, $productB, 8);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/reports/movements?type=sale')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_name', 'Producto A')
            ->assertJsonPath('data.0.type', 'sale')
            ->assertJsonPath('data.0.quantity', 3);
    }

    public function test_reports_require_reports_view_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/stock')
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

        $branch = Branch::create(['name' => "Sucursal {$suffix}", 'code' => "BR-{$suffix}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$suffix}", 'code' => "WH-{$suffix}"]);
        $product = Product::create(['name' => "Producto {$suffix}", 'sku' => "SKU-{$suffix}"]);

        return [$warehouse, $product];
    }

    private function reportUser(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, 'Auditor', ['reports.view']);

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

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
