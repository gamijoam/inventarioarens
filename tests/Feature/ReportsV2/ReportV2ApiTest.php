<?php

namespace Tests\Feature\ReportsV2;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\ReportsV2\Services\ReportQueryService;
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

class ReportV2ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_gets_org_wide_sales_by_company_without_mixing(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $yaracal = $this->spinoff($group, 'Yaracal', 'yaracal');
        $this->seedSales($tucacas, 0);
        $this->seedSales($yaracal, 500);

        $owner = $this->ownerOf($group, [$tucacas, $yaracal]);

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson('/api/reports/v2/sales_by_company?scope=organization')
            ->assertOk()
            ->assertJsonPath('data.report.code', 'sales_by_company')
            ->assertJsonPath('data.scope', 'organization')
            ->assertJsonPath('data.totals.sales_count', 2)
            ->assertJsonPath('data.totals.sales_total', 700);

        $rows = collect($response->json('data.rows'));
        $this->assertCount(2, $rows);
        $this->assertSame(100, $rows->firstWhere('label', 'Tucacas')['sales_total']);
        $this->assertSame(600, $rows->firstWhere('label', 'Yaracal')['sales_total']);
    }

    public function test_tenant_scope_returns_only_current_company(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $yaracal = $this->spinoff($group, 'Yaracal', 'yaracal');
        $this->seedSales($tucacas, 0);
        $this->seedSales($yaracal, 500);

        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/sales_overview?scope=tenant')
            ->assertOk()
            ->assertJsonPath('data.totals.sales_count', 1)
            ->assertJsonPath('data.totals.sales_total', 100);
    }

    public function test_sales_overview_groups_by_dimension(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedSales($tucacas, 0);
        $this->seedSales($tucacas, 0, now()->subDays(3));

        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/sales_overview?scope=tenant&dimension=day')
            ->assertOk()
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.totals.sales_count', 2);
    }

    public function test_non_owner_gets_403_for_organization_scope(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');

        $admin = $this->userInSpinoff($tucacas, 'Administrador');

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/sales_overview?scope=organization')
            ->assertForbidden();
    }

    public function test_user_without_report_permission_gets_403(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');

        $cashier = $this->userInSpinoff($tucacas, 'Vendedor');

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/sales_overview?scope=tenant')
            ->assertForbidden();
    }

    public function test_unknown_report_returns_404(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $owner = $this->ownerOf($group, [$tucacas]);

        $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/no_existe')
            ->assertNotFound();
    }

    public function test_stock_report_with_warehouse_filter_and_low_stock(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        [$warehouse, $productLow, $productHigh] = $this->seedStock($tucacas);
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/stock_by_product?scope=tenant')
            ->assertOk()
            ->assertJsonPath('data.totals.stock_qty', 17);

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/stock_by_product?scope=tenant&low_stock_only=1&low_stock_threshold=3')
            ->assertOk()
            ->assertJsonPath('data.totals.stock_qty', 2);
    }

    public function test_payment_method_report_aggregates_pos_payments(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedPosPayment($tucacas, 'cash', 80);
        $this->seedPosPayment($tucacas, 'mobile_payment', 20);
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $response = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/sales_by_payment_method?scope=tenant')
            ->assertOk();

        $rows = collect($response->json('data.rows'));
        $this->assertSame(80, $rows->firstWhere('label', 'cash')['usd_paid']);
        $this->assertSame(20, $rows->firstWhere('label', 'mobile_payment')['usd_paid']);
        $this->assertSame(100, $response->json('data.totals.usd_paid'));
        $this->assertSame(0, $response->json('data.totals.ves_paid'));
        $this->assertSame(0, $response->json('data.totals.usd_equiv'));
    }

    public function test_payment_method_report_splits_actual_currency_vs_equivalent(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedPosPayment($tucacas, 'PAGO_MOVIL', 100, 'VES');

        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $response = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/sales_by_payment_method?scope=tenant')
            ->assertOk();

        $row = collect($response->json('data.rows'))->firstWhere('label', 'PAGO_MOVIL');
        $this->assertSame(0, $row['usd_paid']);
        $this->assertSame(7400, $row['ves_paid']);
        $this->assertSame(100, $row['usd_equiv']);
        $this->assertSame(74, $row['rate']);
    }

    public function test_finance_reports_return_balances_per_party(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedFinance($tucacas);
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/receivables_by_customer?scope=tenant')
            ->assertOk()
            ->assertJsonPath('data.totals.balance', 120);

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/payables_by_supplier?scope=tenant')
            ->assertOk()
            ->assertJsonPath('data.totals.balance', 45);
    }

    public function test_query_count_is_bounded_regardless_of_company_count(): void
    {
        $group = $this->group();
        $a = $this->spinoff($group, 'A', 'a');
        $b = $this->spinoff($group, 'B', 'b');
        $this->seedSales($a, 0);
        $this->seedSales($b, 100);

        $service = app(ReportQueryService::class);
        app(TenantManager::class)->set($group);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->run('sales_overview', ['scope' => 'organization']);
        $queriesWithTwo = count(DB::getQueryLog());

        $c = $this->spinoff($group, 'C', 'c');
        $d = $this->spinoff($group, 'D', 'd');
        $this->seedSales($c, 200);
        $this->seedSales($d, 300);

        DB::flushQueryLog();
        $service->run('sales_overview', ['scope' => 'organization']);
        $queriesWithFour = count(DB::getQueryLog());

        $this->assertLessThanOrEqual($queriesWithTwo + 1, $queriesWithFour);
        $this->assertLessThanOrEqual(4, $queriesWithFour);
    }

    public function test_catalog_lists_reports_accessible_by_permission(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $response = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('sales_overview', $codes);
        $this->assertContains('stock_by_product', $codes);
        $this->assertContains('receivables_by_customer', $codes);

        $salesByCompany = collect($response->json('data'))->firstWhere('code', 'sales_by_company');
        $this->assertSame(true, $salesByCompany['org_supported']);
    }

    public function test_export_csv_streams_header_and_rows(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedSales($tucacas, 0);
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $response = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->get('/api/reports/v2/sales_overview/export?format=csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->getContent();
        $this->assertStringContainsString('sales_total', $content);
        $this->assertStringContainsString('Totales', $content);
        $this->assertStringContainsString('100', $content);
    }

    public function test_export_xlsx_returns_spreadsheet(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedSales($tucacas, 0);
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->get('/api/reports/v2/sales_overview/export?format=xlsx')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_pdf_returns_pdf(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedSales($tucacas, 0);
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->get('/api/reports/v2/sales_overview/export?format=pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_export_requires_permission(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $cashier = $this->userInSpinoff($tucacas, 'Vendedor');

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->get('/api/reports/v2/sales_overview/export?format=csv')
            ->assertForbidden();
    }

    public function test_export_rejects_unknown_format(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->get('/api/reports/v2/sales_overview/export?format=docx')
            ->assertStatus(422);
    }

    public function test_sales_overview_ticket_avg_total_is_weighted_average(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedSales($tucacas, 0);
        $this->seedSales($tucacas, 0, now()->subDays(2));
        $this->seedSales($tucacas, 0, now()->subDays(5));
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $response = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/sales_overview?scope=tenant&dimension=day')
            ->assertOk();

        $this->assertSame(300, $response->json('data.totals.sales_total'));
        $this->assertSame(3, $response->json('data.totals.sales_count'));
        $this->assertSame(100, $response->json('data.totals.ticket_avg'));
    }

    public function test_org_report_filters_by_single_company(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $yaracal = $this->spinoff($group, 'Yaracal', 'yaracal');
        $this->seedSales($tucacas, 0);
        $this->seedSales($yaracal, 500);
        $owner = $this->ownerOf($group, [$tucacas, $yaracal]);

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson("/api/reports/v2/sales_by_company?scope=organization&company_id={$tucacas->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data.rows'));
        $this->assertSame(100, $response->json('data.totals.sales_total'));
        $this->assertSame(1, $response->json('data.totals.sales_count'));
    }

    public function test_org_report_rejects_company_outside_group(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedSales($tucacas, 0);
        $foreign = Tenant::create(['name' => 'Otra', 'slug' => 'otra-empresa']);
        $owner = $this->ownerOf($group, [$tucacas]);

        $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson("/api/reports/v2/sales_by_company?scope=organization&company_id={$foreign->id}")
            ->assertNotFound();
    }

    public function test_org_reports_support_company_dimension(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $yaracal = $this->spinoff($group, 'Yaracal', 'yaracal');
        $this->seedSales($tucacas, 0);
        $this->seedSales($yaracal, 500);
        $owner = $this->ownerOf($group, [$tucacas, $yaracal]);

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson('/api/reports/v2/sales_overview?scope=organization&dimension=company')
            ->assertOk();

        $rows = collect($response->json('data.rows'));
        $this->assertSame(100, $rows->firstWhere('label', 'Tucacas')['sales_total']);
        $this->assertSame(600, $rows->firstWhere('label', 'Yaracal')['sales_total']);
        $this->assertSame(700, $response->json('data.totals.sales_total'));
    }

    public function test_payment_method_report_marks_usd_payment_without_bs_equivalents(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedPosPayment($tucacas, 'cash', 80);
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/sales_by_payment_method?scope=tenant')
            ->assertOk()
            ->assertJsonPath('data.totals.usd_paid', 80)
            ->assertJsonPath('data.totals.ves_paid', 0)
            ->assertJsonPath('data.totals.usd_equiv', 0);
    }

    public function test_sales_overview_splits_actual_currency_from_payments(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedPosPayment($tucacas, 'cash', 100);
        $this->seedPosPayment($tucacas, 'PAGO_MOVIL', 50, 'VES');
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $response = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/sales_overview?scope=tenant')
            ->assertOk();

        $this->assertSame(150, $response->json('data.totals.sales_total'));
        $this->assertSame(100, $response->json('data.totals.usd_paid'));
        $this->assertSame(3700, $response->json('data.totals.ves_paid'));
        $this->assertSame(50, $response->json('data.totals.usd_equiv'));
    }

    public function test_sales_by_company_splits_actual_currency_from_payments(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedPosPayment($tucacas, 'cash', 100);
        $this->seedPosPayment($tucacas, 'PAGO_MOVIL', 50, 'VES');
        $owner = $this->ownerOf($group, [$tucacas]);

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson('/api/reports/v2/sales_by_company?scope=organization')
            ->assertOk();

        $this->assertSame(150, $response->json('data.totals.sales_total'));
        $this->assertSame(100, $response->json('data.totals.usd_paid'));
        $this->assertSame(3700, $response->json('data.totals.ves_paid'));
        $this->assertSame(50, $response->json('data.totals.usd_equiv'));
    }

    public function test_sales_overview_exposes_implied_exchange_rate(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedPosPayment($tucacas, 'cash', 100);
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $response = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/sales_overview?scope=tenant')
            ->assertOk();

        $this->assertSame(74, $response->json('data.rate'));
        $this->assertSame(74, $response->json('data.rows.0.rate'));
    }

    public function test_payment_method_report_exposes_implied_exchange_rate(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $this->seedPosPayment($tucacas, 'cash', 80);
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $response = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2/sales_by_payment_method?scope=tenant')
            ->assertOk();

        $this->assertSame(74, $response->json('data.rate'));
        $this->assertSame(74, $response->json('data.rows.0.rate'));
    }

    public function test_catalog_exposes_has_local_amounts_flag(): void
    {
        $group = $this->group();
        $tucacas = $this->spinoff($group, 'Tucacas', 'tucacas');
        $manager = $this->userInSpinoff($tucacas, 'Gerente');

        $response = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tucacas->slug)
            ->getJson('/api/reports/v2')
            ->assertOk();

        $overview = collect($response->json('data'))->firstWhere('code', 'sales_overview');
        $stock = collect($response->json('data'))->firstWhere('code', 'stock_by_product');
        $this->assertSame(true, $overview['has_local_amounts']);
        $this->assertSame(false, $stock['has_local_amounts']);
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
        return Tenant::create(['name' => 'Grupo', 'slug' => 'grupo']);
    }

    private function spinoff(Tenant $group, string $name, string $slug): Tenant
    {
        return Tenant::create(['name' => $name, 'slug' => $slug, 'parent_id' => $group->id]);
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

    private function seedSales(Tenant $tenant, int $offset, ?\DateTimeInterface $confirmedAt = null): void
    {
        $this->useTenant($tenant);
        $user = User::factory()->create();
        $base = 100 + $offset;
        Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => $base,
            'total_local_amount' => round($base * 74, 2),
            'created_by' => $user->id,
            'confirmed_at' => $confirmedAt ?? now(),
        ]);
    }

    private function seedStock(Tenant $tenant): array
    {
        $this->useTenant($tenant);
        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-1']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen 1', 'code' => 'WH-1']);
        $productLow = Product::create(['name' => 'Bajo', 'sku' => 'LOW-1', 'tracking_type' => 'quantity', 'base_price' => 10, 'sale_currency' => 'USD', 'last_purchase_cost' => 5]);
        $productHigh = Product::create(['name' => 'Alto', 'sku' => 'HIGH-1', 'tracking_type' => 'quantity', 'base_price' => 20, 'sale_currency' => 'USD', 'last_purchase_cost' => 8]);

        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $productLow->id, 'quantity_available' => 2]);
        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $productHigh->id, 'quantity_available' => 15]);

        return [$warehouse, $productLow, $productHigh];
    }

    private function seedPosPayment(Tenant $tenant, string $method, float $usdAmount, string $currency = 'USD'): void
    {
        $this->useTenant($tenant);
        $rate = 74.0;
        $base = $usdAmount;
        $local = round($base * $rate, 2);
        $amount = $currency === 'VES' ? $local : $base;
        $sale = Sale::create(['status' => Sale::STATUS_CONFIRMED, 'total_base_amount' => $base, 'total_local_amount' => $local, 'confirmed_at' => now()]);
        $order = PosOrder::create(['sale_id' => $sale->id, 'status' => PosOrder::STATUS_PAID, 'paid_base_amount' => $base, 'paid_at' => now()]);
        PosPayment::create([
            'pos_order_id' => $order->id,
            'method' => $method,
            'currency' => $currency,
            'amount' => $amount,
            'amount_base' => $base,
            'amount_local' => $local,
            'status' => 'captured',
        ]);
    }

    private function seedFinance(Tenant $tenant): void
    {
        $this->useTenant($tenant);
        $customer = Customer::create(['document_type' => 'V', 'document_number' => '12345', 'name' => 'Cliente 1', 'is_active' => true]);
        $supplier = Supplier::create(['name' => 'Proveedor 1', 'document_type' => Supplier::DOCUMENT_J, 'document_number' => 'J-1']);
        $sale = Sale::create(['status' => Sale::STATUS_CONFIRMED, 'total_base_amount' => 120, 'confirmed_at' => now()]);
        $purchase = PurchaseOrder::create(['supplier_id' => $supplier->id, 'status' => PurchaseOrder::STATUS_RECEIVED, 'purchase_currency' => 'USD', 'total_base_amount' => 45, 'received_at' => now()]);

        AccountsReceivable::create(['customer_id' => $customer->id, 'sale_id' => $sale->id, 'status' => AccountsReceivable::STATUS_PARTIAL, 'document_number' => 'CXC-1', 'balance_base_amount' => 120, 'opened_at' => now()]);
        AccountsPayable::create(['supplier_id' => $supplier->id, 'purchase_order_id' => $purchase->id, 'status' => AccountsPayable::STATUS_PENDING, 'document_number' => 'CXP-1', 'balance_base_amount' => 45, 'opened_at' => now()]);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
