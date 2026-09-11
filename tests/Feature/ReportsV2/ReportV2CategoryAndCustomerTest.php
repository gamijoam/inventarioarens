<?php

namespace Tests\Feature\ReportsV2;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReportV2CategoryAndCustomerTest extends TestCase
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

    public function test_catalog_includes_sales_by_category_and_sales_by_customer(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Test', 'slug' => 'empresa-test']);
        $user = $this->manager($tenant);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/v2')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('sales_by_category', $codes);
        $this->assertContains('sales_by_customer', $codes);
    }

    public function test_sales_by_category_aggregates_properly(): void
    {
        $tenant = Tenant::create(['name' => 'Papelería Central', 'slug' => 'papeleria-central']);
        $this->useTenant($tenant);

        $user = $this->manager($tenant);
        $branch = Branch::create(['name' => 'Sucursal Principal', 'code' => 'BR-01']);
        $warehouse = Warehouse::create(['name' => 'Almacén 1', 'code' => 'WH-1', 'branch_id' => $branch->id]);

        $catPapeleria = Category::create(['name' => 'Papelería', 'slug' => 'papeleria']);
        $catArte = Category::create(['name' => 'Arte', 'slug' => 'arte']);

        $prodLibreta = Product::create(['name' => 'Libreta', 'sku' => 'LIB-1', 'sale_currency' => 'USD', 'sale_price' => 10]);
        $prodLibreta->categories()->attach($catPapeleria->id, ['tenant_id' => $tenant->id]);

        $prodPincel = Product::create(['name' => 'Pincel', 'sku' => 'PIN-1', 'sale_currency' => 'USD', 'sale_price' => 5]);
        $prodPincel->categories()->attach($catArte->id, ['tenant_id' => $tenant->id]);

        $prodSinCat = Product::create(['name' => 'Pegamento', 'sku' => 'PEG-1', 'sale_currency' => 'USD', 'sale_price' => 3]);

        // Sale 1: 3 Libretas ($30) + 2 Pegamentos ($6)
        $sale1 = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => 36.0,
            'total_local_amount' => 0,
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale1->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $prodLibreta->id,
            'quantity' => 3,
            'sale_currency' => 'USD',
            'unit_price' => 10,
            'total_amount' => 30,
            'base_unit_price' => 10,
            'base_total_amount' => 30,
        ]);
        SaleItem::create([
            'sale_id' => $sale1->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $prodSinCat->id,
            'quantity' => 2,
            'sale_currency' => 'USD',
            'unit_price' => 3,
            'total_amount' => 6,
            'base_unit_price' => 3,
            'base_total_amount' => 6,
        ]);

        // Sale 2: 4 Pinceles ($20)
        $sale2 = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => 20.0,
            'total_local_amount' => 0,
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale2->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $prodPincel->id,
            'quantity' => 4,
            'sale_currency' => 'USD',
            'unit_price' => 5,
            'total_amount' => 20,
            'base_unit_price' => 5,
            'base_total_amount' => 20,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/v2/sales_by_category?scope=tenant')
            ->assertOk()
            ->assertJsonPath('data.report.code', 'sales_by_category')
            ->assertJsonPath('data.totals.amount', 56);

        $rows = collect($response->json('data.rows'));
        $this->assertCount(3, $rows);

        $papRow = $rows->firstWhere('label', 'Papelería');
        $this->assertNotNull($papRow);
        $this->assertEquals(30, $papRow['amount']);
        $this->assertEquals(3, $papRow['units']);

        $arteRow = $rows->firstWhere('label', 'Arte');
        $this->assertNotNull($arteRow);
        $this->assertEquals(20, $arteRow['amount']);
        $this->assertEquals(4, $arteRow['units']);

        $sinCatRow = $rows->firstWhere('label', 'Sin categoría');
        $this->assertNotNull($sinCatRow);
        $this->assertEquals(6, $sinCatRow['amount']);
        $this->assertEquals(2, $sinCatRow['units']);
    }

    public function test_sales_by_customer_aggregates_properly(): void
    {
        $tenant = Tenant::create(['name' => 'Papelería Central', 'slug' => 'papeleria-central']);
        $this->useTenant($tenant);

        $user = $this->manager($tenant);

        $customer = Customer::create([
            'name' => 'Librería Escolar CA',
            'document_type' => 'J',
            'document_number' => '555666777',
        ]);

        // Sale to specific customer: $150
        Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'customer_id' => $customer->id,
            'total_base_amount' => 150.0,
            'total_local_amount' => 0,
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);

        // Sale to generic / walk-in customer: $50
        Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'customer_id' => null,
            'total_base_amount' => 50.0,
            'total_local_amount' => 0,
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/v2/sales_by_customer?scope=tenant')
            ->assertOk()
            ->assertJsonPath('data.report.code', 'sales_by_customer')
            ->assertJsonPath('data.totals.sales_total', 200)
            ->assertJsonPath('data.totals.sales_count', 2);

        $rows = collect($response->json('data.rows'));
        $this->assertCount(2, $rows);

        $custRow = $rows->firstWhere('label', 'Librería Escolar CA');
        $this->assertNotNull($custRow);
        $this->assertEquals(150, $custRow['sales_total']);

        $finalRow = $rows->firstWhere('label', 'Consumidor Final');
        $this->assertNotNull($finalRow);
        $this->assertEquals(50, $finalRow['sales_total']);
    }

    private function manager(Tenant $tenant): User
    {
        $this->useTenant($tenant);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $role = Role::findOrCreate('Gerente', 'web');
        $role->syncPermissions(['reports.sales.view']);
        $user->assignRole($role);

        return $user;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
