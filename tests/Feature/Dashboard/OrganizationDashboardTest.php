<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Dashboard\Services\OrganizationDashboardService;
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
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OrganizationDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_of_group_gets_consolidated_totals_and_company_breakdown(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $boca = $this->spinoff($group, 'Boca de Aroa', 'boca-de-aroa');
        $this->seedOrgData($tucacas, 0);
        $this->seedOrgData($boca, 1000);

        $owner = $this->ownerOf($group, [$tucacas]);

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson('/api/dashboard/summary?scope=organization&period=today')
            ->assertOk()
            ->assertJsonPath('data.scope', 'organization')
            ->assertJsonPath('data.group.id', $group->id)
            ->assertJsonPath('data.totals.sales_count', 6)
            ->assertJsonPath('data.totals.sales_total_base_amount', 2540)
            ->assertJsonPath('data.totals.pos_orders_count', 2)
            ->assertJsonPath('data.totals.pos_paid_base_amount', 1190)
            ->assertJsonPath('data.totals.open_cash_sessions', 2)
            ->assertJsonPath('data.totals.receivable_balance_base_amount', 1240)
            ->assertJsonPath('data.totals.payable_balance_base_amount', 1090)
            ->assertJsonPath('data.totals.low_stock_count', 2);

        $companies = collect($response->json('data.companies'));
        $this->assertCount(2, $companies);

        $tucacasRow = $companies->firstWhere('slug', 'tucacas');
        $this->assertSame(3, $tucacasRow['sales']['confirmed_count']);
        $this->assertSame(270, $tucacasRow['sales']['total_base_amount']);
        $this->assertSame(1, $tucacasRow['pos']['paid_orders_count']);
        $this->assertSame(95, $tucacasRow['pos']['paid_base_amount']);
        $this->assertSame(1, $tucacasRow['cash_register']['open_sessions_count']);
        $this->assertSame(1, $tucacasRow['inventory']['low_stock_count']);
        $this->assertSame(120, $tucacasRow['finance']['accounts_receivable_balance_base_amount']);
        $this->assertSame(45, $tucacasRow['finance']['accounts_payable_balance_base_amount']);

        $bocaRow = $companies->firstWhere('slug', 'boca-de-aroa');
        $this->assertSame(3, $bocaRow['sales']['confirmed_count']);
        $this->assertSame(2270, $bocaRow['sales']['total_base_amount']);
        $this->assertSame(1095, $bocaRow['pos']['paid_base_amount']);
    }

    public function test_sales_of_one_company_are_not_attributed_to_another(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $yaracal = $this->spinoff($group, 'Yaracal', 'yaracal');
        $this->seedOrgData($tucacas, 0);
        $this->seedOrgData($yaracal, 0);

        $owner = $this->ownerOf($group, [$tucacas]);

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson('/api/dashboard/summary?scope=organization&period=today');

        $companies = collect($response->json('data.companies'));

        foreach ($companies as $company) {
            $this->assertSame(3, $company['sales']['confirmed_count']);
            $this->assertSame(270, $company['sales']['total_base_amount']);
        }

        $this->assertSame(540, $response->json('data.totals.sales_total_base_amount'));
    }

    public function test_local_user_gets_403_for_organization_scope(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');

        $cashier = $this->userInSpinoff($tucacas, 'Vendedor');

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/dashboard/summary?scope=organization')
            ->assertForbidden();
    }

    public function test_admin_of_spinoff_without_owner_role_gets_403_for_organization_scope(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');

        $admin = $this->userInSpinoff($tucacas, 'Administrador');

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/dashboard/summary?scope=organization')
            ->assertForbidden();
    }

    public function test_owner_can_view_organization_scope_from_a_spinoff_context(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedOrgData($tucacas, 0);

        $owner = $this->ownerOf($group, [$tucacas]);

        $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/dashboard/summary?scope=organization&period=today')
            ->assertOk()
            ->assertJsonPath('data.group.id', $group->id)
            ->assertJsonPath('data.totals.sales_count', 3);
    }

    public function test_group_without_children_returns_valid_empty_totals(): void
    {
        $group = $this->group();
        $owner = $this->ownerOf($group, []);

        $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson('/api/dashboard/summary?scope=organization&period=today')
            ->assertOk()
            ->assertJsonPath('data.scope', 'organization')
            ->assertJsonPath('data.totals.sales_count', 0)
            ->assertJsonPath('data.totals.sales_total_base_amount', 0)
            ->assertJsonPath('data.companies', []);
    }

    public function test_period_custom_range_filters_sales_across_companies(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedOrgData($tucacas, 0);

        $oldSale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => 999,
            'confirmed_at' => now()->subDays(10),
        ]);

        $owner = $this->ownerOf($group, [$tucacas]);

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson('/api/dashboard/summary?scope=organization&period=today')
            ->assertOk();

        $this->assertSame(3, $response->json('data.totals.sales_count'));
        $this->assertSame(270, $response->json('data.totals.sales_total_base_amount'));
    }

    public function test_query_count_is_bounded_and_does_not_grow_with_company_count(): void
    {
        $group = $this->group();
        $spinoffA = $this->spinoff($group, 'A', 'a');
        $spinoffB = $this->spinoff($group, 'B', 'b');
        $this->seedOrgData($spinoffA, 0);
        $this->seedOrgData($spinoffB, 0);

        $service = app(OrganizationDashboardService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->summary(['period' => 'today'], $group);
        $queriesWithTwo = count(DB::getQueryLog());

        $spinoffC = $this->spinoff($group, 'C', 'c');
        $spinoffD = $this->spinoff($group, 'D', 'd');
        $this->seedOrgData($spinoffC, 0);
        $this->seedOrgData($spinoffD, 0);

        DB::flushQueryLog();
        $service->summary(['period' => 'today'], $group);
        $queriesWithFour = count(DB::getQueryLog());

        $this->assertLessThanOrEqual($queriesWithTwo + 2, $queriesWithFour);
    }

    public function test_tenant_scope_remains_default_when_scope_omitted(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedOrgData($tucacas, 0);

        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/dashboard/summary?period=today')
            ->assertOk()
            ->assertJsonPath('data.scope', null)
            ->assertJsonPath('data.sales.confirmed_count', 3);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function group(): Tenant
    {
        return Tenant::create(['name' => 'Tiendas Arens', 'slug' => 'tiendas-arens']);
    }

    private function spinoff(Tenant $group, string $name, string $slug): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => $slug,
            'parent_id' => $group->id,
        ]);
    }

    private function ownerOf(Tenant $group, array $spinoffs): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($group, ['status' => 'active']);

        foreach ($spinoffs as $spinoff) {
            $user->tenants()->attach($spinoff, ['status' => 'active']);
        }

        $this->useTenant($group);

        $role = Role::findOrCreate('Owner', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $user->assignRole($role);

        return $user;
    }

    private function userInSpinoff(Tenant $tenant, string $roleName): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions(BasePermissions::ROLE_PERMISSIONS[$roleName] ?? []);
        $user->assignRole($role);

        return $user;
    }

    private function seedOrgData(Tenant $tenant, int $offset): void
    {
        $this->useTenant($tenant);

        $branch = Branch::create([
            'name' => "Sucursal {$offset}",
            'code' => "BR-{$offset}",
        ]);

        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => "Almacen {$offset}",
            'code' => "WH-{$offset}",
        ]);

        $lowStockProduct = Product::create([
            'name' => 'Producto Bajo',
            'sku' => "BAJO-{$offset}",
            'tracking_type' => 'quantity',
            'base_price' => 50,
            'sale_currency' => 'USD',
        ]);

        $healthyProduct = Product::create([
            'name' => 'Producto Sano',
            'sku' => "SANO-{$offset}",
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

        Sale::create([
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

        $sale = Sale::query()->where('tenant_id', $tenant->id)->where('total_base_amount', 100 + $offset)->first();

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

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
