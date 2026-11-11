<?php

namespace Tests\Feature\DataImport;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TemplateDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('data_import.view', 'web');

        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => bcrypt('secret123'),
        ]);
        $this->admin->tenants()->attach($this->tenant->id, ['status' => 'active']);
        setPermissionsTeamId($this->tenant->id);
        $this->admin->givePermissionTo(['data_import.view']);
    }

    public function test_template_for_branches_returns_csv_with_headers_and_examples(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/branches');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->getContent();
        $this->assertStringStartsWith('code,name,status', $content);
        $this->assertStringContainsString('PRINCIPAL,Sucursal Principal,active', $content);
        $this->assertStringContainsString('NORTE,Sucursal Norte,active', $content);
    }

    public function test_template_for_warehouses_includes_tenant_branch_values(): void
    {
        Branch::create(['code' => 'PRINCIPAL', 'name' => 'Principal', 'status' => 'active']);
        Branch::create(['code' => 'NORTE', 'name' => 'Norte', 'status' => 'active']);

        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/warehouses');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('PRINCIPAL,Almacen Principal,PRINCIPAL,active', $content);
        $this->assertStringContainsString('NORTE', $content);
    }

    public function test_template_rejects_invalid_entity(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/bad_entity');

        $response->assertStatus(422);
    }

    public function test_template_for_products_includes_separator_aware_json_column(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/price_lists');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('payment_method_codes;prices', $content);
    }

    public function test_current_download_returns_tenant_products(): void
    {
        Product::create([
            'sku' => 'SKU-CURRENT-1',
            'name' => 'Producto Actual',
            'tracking_type' => 'quantity',
            'base_price' => 12.5,
            'sale_currency' => 'USD',
            'unit_of_measure' => 'unit',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/products?current=1');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringStartsWith('sku,name,barcode', $content);
        $this->assertStringContainsString('SKU-CURRENT-1,Producto Actual', $content);
    }

    public function test_simple_product_template_contains_only_fields_for_basic_product_upload(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/products?simple=1');

        $response->assertOk();
        $this->assertStringStartsWith(
            'sku,name,barcode,description,category,precio_1,precio_2,precio_3,unit_of_measure,tracking_type,base_price,sale_currency,is_active',
            $response->getContent(),
        );
        $this->assertStringNotContainsString('category_slugs', $response->getContent());
        $this->assertStringNotContainsString('stock_inicial', $response->getContent());
    }

    public function test_simple_current_products_download_includes_category(): void
    {
        $category = \App\Modules\Products\Models\Category::create([
            'name' => 'Balanza',
            'slug' => 'balanza',
            'is_active' => true,
        ]);

        $product = Product::create([
            'sku' => 'SKU-BAL-1',
            'name' => 'Balanza Digital',
            'tracking_type' => 'quantity',
            'base_price' => 50,
            'sale_currency' => 'USD',
            'unit_of_measure' => 'unit',
            'is_active' => true,
        ]);
        $product->categories()->attach($category->id, ['tenant_id' => $this->tenant->id]);

        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/products?current=1&simple=1');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringStartsWith(
            'sku,name,barcode,description,category,precio_1,precio_2,precio_3,unit_of_measure,tracking_type,base_price,sale_currency,is_active',
            $content,
        );
        $this->assertStringContainsString('SKU-BAL-1,Balanza Digital,,,Balanza,50,50,50,unit,quantity,50', $content);
    }

    public function test_template_download_as_xlsx(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/products?current=1&simple=1&format=xlsx');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attachment; filename="productos_para_actualizar_products.xlsx"', (string) $response->headers->get('Content-Disposition'));
        // XLSX files are zip archives starting with 'PK\x03\x04'
        $this->assertStringStartsWith("PK\x03\x04", $response->getContent());
    }

    public function test_current_download_rejects_entity_without_current_support(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/branches?current=1');

        $response->assertStatus(422);
    }
}
