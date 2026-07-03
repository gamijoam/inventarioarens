<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Products\Models\Product;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summary_returns_real_aggregates_for_current_company(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->dashboardUser($tenant);
        $this->seedDashboardData($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/dashboard/summary?period=today&low_stock_threshold=3')
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.sales.confirmed_count', 3)
            ->assertJsonPath('data.sales.total_base_amount', 270)
            ->assertJsonPath('data.pos.paid_orders_count', 1)
            ->assertJsonPath('data.pos.paid_base_amount', 95)
            ->assertJsonPath('data.cash_register.open_sessions_count', 1)
            ->assertJsonPath('data.inventory.low_stock_count', 1)
            ->assertJsonPath('data.inventory.low_stock_items.0.product_name', 'Samsung A06')
            ->assertJsonPath('data.finance.accounts_receivable_balance_base_amount', 120)
            ->assertJsonPath('data.finance.accounts_payable_balance_base_amount', 45);
    }

    public function test_dashboard_summary_does_not_mix_companies(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->dashboardUser($tenantA);

        $this->seedDashboardData($tenantA);
        $this->seedDashboardData($tenantB, 1000);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.sales.total_base_amount', 270)
            ->assertJsonPath('data.finance.accounts_receivable_balance_base_amount', 120)
            ->assertJsonPath('data.inventory.low_stock_count', 1);
    }

    public function test_dashboard_summary_requires_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/dashboard/summary')
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

    private function seedDashboardData(Tenant $tenant, int $offset = 0): void
    {
        $this->useTenant($tenant);

        $branch = Branch::create([
            'name' => "Principal {$offset}",
            'code' => "BR-{$offset}",
        ]);

        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => "Almacen {$offset}",
            'code' => "WH-{$offset}",
        ]);

        $lowStockProduct = Product::create([
            'name' => 'Samsung A06',
            'sku' => "A06-{$offset}",
            'tracking_type' => 'quantity',
            'base_price' => 120,
            'sale_currency' => 'USD',
        ]);

        $healthyProduct = Product::create([
            'name' => 'Audifonos',
            'sku' => "AUD-{$offset}",
            'tracking_type' => 'quantity',
            'base_price' => 20,
            'sale_currency' => 'USD',
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $lowStockProduct->id,
            'quantity_available' => 2,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $healthyProduct->id,
            'quantity_available' => 12,
        ]);

        $sale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => 100 + $offset,
            'confirmed_at' => now(),
        ]);

        Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => 75,
            'confirmed_at' => now(),
        ]);

        Sale::create([
            'status' => Sale::STATUS_DRAFT,
            'total_base_amount' => 900,
        ]);

        $posSale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => 95 + $offset,
            'confirmed_at' => now(),
        ]);

        PosOrder::create([
            'sale_id' => $posSale->id,
            'status' => PosOrder::STATUS_PAID,
            'paid_base_amount' => 95 + $offset,
            'paid_at' => now(),
        ]);

        CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cashier_id' => User::factory()->create()->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);

        $supplier = Supplier::create([
            'name' => "Proveedor {$offset}",
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => "J-{$offset}",
        ]);

        $purchase = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'total_base_amount' => 45 + $offset,
            'received_at' => now(),
        ]);

        AccountsReceivable::create([
            'sale_id' => $sale->id,
            'status' => AccountsReceivable::STATUS_PARTIAL,
            'document_number' => "CXC-{$offset}",
            'balance_base_amount' => 120 + $offset,
            'opened_at' => now(),
        ]);

        AccountsPayable::create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $purchase->id,
            'status' => AccountsPayable::STATUS_PENDING,
            'document_number' => "CXP-{$offset}",
            'balance_base_amount' => 45 + $offset,
            'opened_at' => now(),
        ]);
    }

    private function dashboardUser(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, 'Dashboard', ['finance_reports.view', 'reports.view', 'sales.view', 'pos.view', 'products.view', 'cash_register.view']);

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
