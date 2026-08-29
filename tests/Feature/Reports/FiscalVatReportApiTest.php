<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FiscalVatReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_iva_report_groups_confirmed_fiscal_snapshots_and_excludes_other_statuses_and_tenants(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');
        $tenantA = Tenant::create(['name' => 'Empresa IVA A', 'slug' => 'empresa-iva-a']);
        $tenantB = Tenant::create(['name' => 'Empresa IVA B', 'slug' => 'empresa-iva-b']);
        $userA = $this->userInTenant($tenantA, ['reports.sales.view']);
        $userB = $this->userInTenant($tenantB, ['reports.sales.view']);

        $this->useTenant($tenantA);
        [$warehouseA, $productA] = $this->inventoryForTenant('Almacen A', 'IVA-A');
        $customerA = Customer::create([
            'name' => 'Cliente A',
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => '100',
        ]);
        $confirmed = $this->saleWithItems($tenantA, $userA, $warehouseA, $productA, $customerA, Sale::STATUS_CONFIRMED, [
            $this->taxedLine(),
            $this->exemptLine(),
        ]);
        $this->saleWithItems($tenantA, $userA, $warehouseA, $productA, $customerA, Sale::STATUS_DRAFT, [
            $this->lineSnapshot('IVA16', 'taxable', 900, 144, 1044),
        ]);

        $this->useTenant($tenantB);
        [$warehouseB, $productB] = $this->inventoryForTenant('Almacen B', 'IVA-B');
        $customerB = Customer::create([
            'name' => 'Cliente B',
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => '200',
        ]);
        $this->saleWithItems($tenantB, $userB, $warehouseB, $productB, $customerB, Sale::STATUS_CONFIRMED, [
            $this->lineSnapshot('IVA16', 'taxable', 999, 159.84, 1158.84),
        ]);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/reports/fiscal/iva?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.summary.sales_count', 1)
            ->assertJsonPath('data.summary.taxable_base_amount', 100)
            ->assertJsonPath('data.summary.tax_amount', 16)
            ->assertJsonPath('data.summary.exempt_base_amount', 50)
            ->assertJsonPath('data.summary.total_base_amount', 166)
            ->assertJsonPath('data.rows.0.tax_code', 'IVA16')
            ->assertJsonPath('data.rows.0.category', 'taxable')
            ->assertJsonPath('data.rows.0.taxable_base_amount', 100)
            ->assertJsonPath('data.rows.0.tax_amount', 16)
            ->assertJsonPath('data.rows.1.tax_code', 'EXENTO')
            ->assertJsonPath('data.rows.1.category', 'exempt')
            ->assertJsonPath('data.rows.1.exempt_base_amount', 50)
            ->assertJsonMissing(['tax_amount' => 159.84]);

        $this->assertNotNull($confirmed);
    }

    public function test_iva_report_requires_report_permission_and_validates_period(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Protegida', 'slug' => 'empresa-protegida']);
        $user = $this->userInTenant($tenant, []);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/fiscal/iva')
            ->assertForbidden();

        $userWithPermission = $this->userInTenant($tenant, ['reports.sales.view']);

        $this
            ->actingAs($userWithPermission)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/fiscal/iva?date_from=2026-08-31&date_to=2026-08-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_iva_report_keeps_legacy_confirmed_sales_visible_as_unclassified(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');
        $tenant = Tenant::create(['name' => 'Empresa Legacy', 'slug' => 'empresa-legacy']);
        $user = $this->userInTenant($tenant, ['reports.sales.view']);
        $this->useTenant($tenant);
        [$warehouse, $product] = $this->inventoryForTenant('Almacen Legacy', 'LEGACY');

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenant->id,
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => 20,
            'total_local_amount' => 20,
            'created_by' => $user->id,
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sale_items')->insert([
            'tenant_id' => $tenant->id,
            'sale_id' => $saleId,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'sale_currency' => Product::CURRENCY_USD,
            'unit_price' => 20,
            'total_amount' => 20,
            'base_unit_price' => 20,
            'base_total_amount' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/fiscal/iva?date=2026-08-15')
            ->assertOk()
            ->assertJsonPath('data.summary.sales_count', 1)
            ->assertJsonPath('data.summary.total_base_amount', 20)
            ->assertJsonPath('data.rows.0.tax_code', 'UNCLASSIFIED')
            ->assertJsonPath('data.rows.0.total_base_amount', 20)
            ->assertJsonPath('data.rows.0.tax_amount', 0);
    }

    private function taxedLine(): array
    {
        return $this->lineSnapshot('IVA16', 'taxable', 100, 16, 116);
    }

    private function exemptLine(): array
    {
        return [
            'fiscal_tax_code' => 'EXENTO',
            'fiscal_tax_name' => 'Exento',
            'fiscal_tax_category' => 'exempt',
            'fiscal_tax_rate' => 0,
            'fiscal_taxable_base_amount' => 0,
            'fiscal_exempt_base_amount' => 50,
            'fiscal_exonerated_base_amount' => 0,
            'fiscal_non_taxable_base_amount' => 0,
            'fiscal_tax_base_amount' => 0,
            'fiscal_total_base_amount' => 50,
            'fiscal_taxable_local_amount' => 0,
            'fiscal_exempt_local_amount' => 50,
            'fiscal_exonerated_local_amount' => 0,
            'fiscal_non_taxable_local_amount' => 0,
            'fiscal_tax_local_amount' => 0,
            'fiscal_total_local_amount' => 50,
        ];
    }

    private function lineSnapshot(string $code, string $category, float $base, float $tax, float $total): array
    {
        return [
            'fiscal_tax_code' => $code,
            'fiscal_tax_name' => $code,
            'fiscal_tax_category' => $category,
            'fiscal_tax_rate' => $tax > 0 ? 16 : 0,
            'fiscal_taxable_base_amount' => $category === 'taxable' ? $base : 0,
            'fiscal_exempt_base_amount' => $category === 'exempt' ? $base : 0,
            'fiscal_exonerated_base_amount' => 0,
            'fiscal_non_taxable_base_amount' => 0,
            'fiscal_tax_base_amount' => $tax,
            'fiscal_total_base_amount' => $total,
            'fiscal_taxable_local_amount' => $category === 'taxable' ? $base : 0,
            'fiscal_exempt_local_amount' => $category === 'exempt' ? $base : 0,
            'fiscal_exonerated_local_amount' => 0,
            'fiscal_non_taxable_local_amount' => 0,
            'fiscal_tax_local_amount' => $tax,
            'fiscal_total_local_amount' => $total,
        ];
    }

    private function saleWithItems(Tenant $tenant, User $user, Warehouse $warehouse, Product $product, Customer $customer, string $status, array $snapshots): Sale
    {
        $this->useTenant($tenant);
        $saleTotal = array_sum(array_column($snapshots, 'fiscal_total_base_amount'));
        $taxableBase = array_sum(array_column($snapshots, 'fiscal_taxable_base_amount'));
        $tax = array_sum(array_column($snapshots, 'fiscal_tax_base_amount'));
        $sale = Sale::create([
            'status' => $status,
            'customer_id' => $customer->id,
            'total_base_amount' => $saleTotal,
            'total_local_amount' => $saleTotal,
            'fiscal_taxable_base_amount' => $taxableBase,
            'fiscal_taxable_local_amount' => $taxableBase,
            'fiscal_exempt_base_amount' => $saleTotal - $taxableBase - $tax,
            'fiscal_exempt_local_amount' => $saleTotal - $taxableBase - $tax,
            'fiscal_tax_base_amount' => $tax,
            'fiscal_tax_local_amount' => $tax,
            'fiscal_snapshot_at' => $status === Sale::STATUS_CONFIRMED ? now() : null,
            'created_by' => $user->id,
            'confirmed_at' => $status === Sale::STATUS_CONFIRMED ? now() : null,
        ]);

        foreach ($snapshots as $snapshot) {
            SaleItem::create(array_merge([
                'sale_id' => $sale->id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'sale_currency' => Product::CURRENCY_USD,
                'unit_price' => $snapshot['fiscal_total_base_amount'],
                'total_amount' => $snapshot['fiscal_total_base_amount'],
                'base_unit_price' => $snapshot['fiscal_total_base_amount'],
                'base_total_amount' => $snapshot['fiscal_total_base_amount'],
                'fiscal_prices_include_tax' => false,
                'fiscal_snapshot_at' => $status === Sale::STATUS_CONFIRMED ? now() : null,
            ], $snapshot));
        }

        return $sale;
    }

    private function inventoryForTenant(string $warehouseName, string $suffix): array
    {
        $branch = Branch::create(['name' => "Sucursal {$suffix}", 'code' => "BR-{$suffix}"]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => $warehouseName,
            'code' => "WH-{$suffix}",
        ]);
        $product = Product::create(['name' => "Producto {$suffix}", 'sku' => "SKU-{$suffix}"]);

        return [$warehouse, $product];
    }

    private function userInTenant(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this->useTenant($tenant);
        $role = Role::findOrCreate('Fiscal Report Test '.md5($tenant->id.implode('|', $permissions)), 'web');
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
