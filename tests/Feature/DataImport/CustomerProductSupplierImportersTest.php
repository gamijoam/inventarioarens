<?php

namespace Tests\Feature\DataImport;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\DataImport\Importers\CustomerImporter;
use App\Modules\DataImport\Importers\BrandImporter;
use App\Modules\DataImport\Importers\CategoryImporter;
use App\Modules\DataImport\Importers\ProductImporter;
use App\Modules\DataImport\Importers\TagImporter;
use App\Modules\DataImport\Importers\SupplierImporter;
use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\Tag;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CustomerProductSupplierImportersTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/cps-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function writeCsv(string $name, string $content): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    private function results(\Generator $gen): array
    {
        return iterator_to_array($gen, false);
    }

    public function test_customer_importer_creates_customer_with_typed_doc(): void
    {
        $path = $this->writeCsv('customers.csv', "document_type,document_number,name,phone,email\nV,12345678,Juan Perez,+584141234567,juan@test.com\nJ,12345678,Empresa X,+584261234567,info@empresa.com\n");

        $results = $this->results((new CustomerImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);
        $this->assertDatabaseHas('customers', ['document_type' => 'V', 'document_number' => '12345678', 'name' => 'Juan Perez']);
        $this->assertDatabaseHas('customers', ['document_type' => 'J', 'document_number' => '12345678', 'name' => 'Empresa X']);
    }

    public function test_customer_importer_skips_duplicate_document(): void
    {
        Customer::create([
            'document_type' => 'V',
            'document_number' => '11111111',
            'name' => 'Existente',
            'is_active' => true,
        ]);

        $path = $this->writeCsv('customers.csv', "document_type,document_number,name\nV,11111111,Duplicado\nV,22222222,Nuevo\n");

        $results = $this->results((new CustomerImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_SKIPPED, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);
    }

    public function test_customer_importer_rejects_invalid_email(): void
    {
        $path = $this->writeCsv('customers.csv', "document_type,document_number,name,email\nV,123,Test,not-an-email\nV,124,Test2,ok@test.com\n");

        $results = $this->results((new CustomerImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);
    }

    public function test_customer_importer_rejects_invalid_document_type(): void
    {
        $path = $this->writeCsv('customers.csv', "document_type,document_number,name\nX,123,Test\n");

        $results = $this->results((new CustomerImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('document_type', $results[0]->errors);
    }

    public function test_supplier_importer_creates_with_optional_document(): void
    {
        $path = $this->writeCsv('suppliers.csv', "document_type,document_number,name,phone\nJ,12345678,Proveedor Mayor,02121234567\n,,Proveedor Sin Doc,\n");

        $results = $this->results((new SupplierImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);
        $this->assertDatabaseHas('suppliers', ['document_type' => 'J', 'document_number' => '12345678']);
        $this->assertDatabaseHas('suppliers', ['name' => 'Proveedor Sin Doc', 'document_type' => null]);
    }

    public function test_supplier_importer_skips_duplicate(): void
    {
        Supplier::create([
            'document_type' => 'J',
            'document_number' => '99999999',
            'name' => 'Existing',
            'is_active' => true,
        ]);
        $path = $this->writeCsv('suppliers.csv', "document_type,document_number,name\nJ,99999999,Dup\n");

        $results = $this->results((new SupplierImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_SKIPPED, $results[0]->status);
    }

    public function test_brand_importer_slugifies_slug_from_text_value(): void
    {
        $path = $this->writeCsv('brands.csv', "slug,name,description,is_active\nAccesorios de computadoras,Accesorios de computadoras,,,true\n");

        $results = $this->results((new BrandImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertDatabaseHas('brands', ['slug' => 'accesorios-de-computadoras', 'name' => 'Accesorios de computadoras']);
    }

    public function test_category_importer_slugifies_slug_and_parent_slug(): void
    {
        $path = $this->writeCsv(
            'categories.csv',
            "slug,name,parent_slug,description,sort_order,is_active\nElectronica,Electronica,,,,true\nAccesorios de computadoras,Accesorios de computadoras,ELECTRONICA,,,true\n"
        );

        $results = $this->results((new CategoryImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);
        $this->assertDatabaseHas('categories', ['slug' => 'electronica', 'name' => 'Electronica']);
        $this->assertDatabaseHas('categories', ['slug' => 'accesorios-de-computadoras', 'name' => 'Accesorios de computadoras']);
    }

    public function test_tag_importer_slugifies_slug_from_text_value(): void
    {
        $path = $this->writeCsv('tags.csv', "slug,name,color\nPromocion Especial,Promocion Especial,#00FF00\n");

        $results = $this->results((new TagImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertDatabaseHas('tags', ['slug' => 'promocion-especial', 'name' => 'Promocion Especial']);
    }

    public function test_product_importer_creates_simple_product(): void
    {
        $path = $this->writeCsv('products.csv', "sku,name,barcode,base_price\nSKU-001,Camisa Negra,7501234567890,15.50\n");

        $results = $this->results((new ProductImporter)->import($path));

        $this->assertCount(1, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-001',
            'name' => 'Camisa Negra',
            'barcode' => '7501234567890',
            'base_price' => '15.5000',
            'tracking_type' => 'quantity',
            'is_active' => true,
        ]);
    }

    public function test_product_importer_parses_decimal_comma_values(): void
    {
        $path = $this->writeCsv('products.csv', "sku,name,base_price,min_stock,max_stock,reorder_quantity\nSKU-COMMA,Producto coma,\"2,5\",\"1,5\",\"10,25\",\"7,5\"\n");

        $results = $this->results((new ProductImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status, json_encode($results[0]->errors ?? []));
        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-COMMA',
            'base_price' => '2.5000',
            'min_stock' => '1.5000',
            'max_stock' => '10.2500',
            'reorder_quantity' => '7.5000',
        ]);
    }

    public function test_product_importer_resolves_brand_categories_and_tags(): void
    {
        $brand = Brand::create(['slug' => 'samsung', 'name' => 'Samsung', 'is_active' => true]);
        $cat1 = Category::create(['slug' => 'electronica', 'name' => 'Electronica', 'is_active' => true]);
        $cat2 = Category::create(['slug' => 'celulares', 'name' => 'Celulares', 'parent_id' => $cat1->id, 'is_active' => true]);
        $tag = Tag::create(['slug' => 'nuevo', 'name' => 'Nuevo']);

        $path = $this->writeCsv(
            'products.csv',
            "sku,name,brand_slug,category_slugs,tag_slugs,base_price\nSKU-X,Galaxy S24,samsung,electronica|celulares,nuevo,500.00\n"
        );

        $results = $this->results((new ProductImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);

        $product = Product::query()->where('sku', 'SKU-X')->first();
        $this->assertNotNull($product);
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertCount(2, $product->categories);
        $this->assertCount(1, $product->tags);
    }

    public function test_product_importer_resolves_uppercase_brand_categories_and_tags_slugs(): void
    {
        $brand = Brand::create(['slug' => 'samsung', 'name' => 'Samsung', 'is_active' => true]);
        $cat1 = Category::create(['slug' => 'electronica', 'name' => 'Electronica', 'is_active' => true]);
        $cat2 = Category::create(['slug' => 'celulares', 'name' => 'Celulares', 'parent_id' => $cat1->id, 'is_active' => true]);
        $tag = Tag::create(['slug' => 'nuevo', 'name' => 'Nuevo']);

        $path = $this->writeCsv(
            'products.csv',
            "sku,name,brand_slug,category_slugs,tag_slugs,base_price\nSKU-UP,Galaxy S24,SAMSUNG,ELECTRONICA|Celulares,NUEVO,500.00\n"
        );

        $results = $this->results((new ProductImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);

        $product = Product::query()->where('sku', 'SKU-UP')->first();
        $this->assertNotNull($product);
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertCount(2, $product->categories);
        $this->assertCount(1, $product->tags);
        $this->assertTrue($product->categories->contains('id', $cat1->id));
        $this->assertTrue($product->categories->contains('id', $cat2->id));
        $this->assertTrue($product->tags->contains('id', $tag->id));
    }

    public function test_product_importer_fails_when_brand_not_found(): void
    {
        $path = $this->writeCsv('products.csv', "sku,name,brand_slug\nSKU-X,Test,marca-fantasma\n");

        $results = $this->results((new ProductImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('brand_slug', $results[0]->errors);
        $this->assertStringContainsString('marca-fantasma', $results[0]->errors['brand_slug']);
    }

    public function test_product_importer_creates_stock_entry_when_stock_inicial_provided(): void
    {
        $branch = Branch::create(['code' => 'MAIN', 'name' => 'Main', 'status' => 'active']);
        $warehouse = Warehouse::create([
            'code' => 'W1',
            'name' => 'Almacen 1',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $user = User::create([
            'name' => 'Op',
            'email' => 'op@test.test',
            'password' => bcrypt('secret'),
        ]);
        Auth::login($user);

        $path = $this->writeCsv(
            'products.csv',
            "sku,name,stock_inicial,almacen_codigo,costo_unitario\nSKU-001,Laptop,10,W1,800.50\n"
        );

        $results = $this->results((new ProductImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status, json_encode($results[0]->errors ?? []));
        $this->assertDatabaseHas('products', ['sku' => 'SKU-001', 'name' => 'Laptop']);
        $this->assertDatabaseHas('product_entries', [
            'reason' => 'Importacion inicial',
        ]);
        $entry = ProductEntry::query()->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame(10.0, (float) $entry->items()->first()->quantity);
        $this->assertSame(800.50, (float) $entry->items()->first()->unit_cost);
    }

    public function test_product_importer_skips_duplicate_sku(): void
    {
        Product::create([
            'sku' => 'DUP-001',
            'name' => 'Original',
            'tracking_type' => 'quantity',
            'is_active' => true,
        ]);
        $path = $this->writeCsv('products.csv', "sku,name\nDUP-001,Duplicado\n");

        $results = $this->results((new ProductImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_SKIPPED, $results[0]->status);
        $this->assertDatabaseHas('products', ['sku' => 'DUP-001', 'name' => 'Original']);
    }

    public function test_product_importer_rejects_max_stock_less_than_min_stock(): void
    {
        $path = $this->writeCsv('products.csv', "sku,name,min_stock,max_stock\nSKU-X,Test,100,50\n");

        $results = $this->results((new ProductImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('max_stock', $results[0]->errors);
    }

    public function test_product_importer_requires_warehouse_when_stock_inicial_given(): void
    {
        $path = $this->writeCsv('products.csv', "sku,name,stock_inicial\nSKU-X,Test,50\n");

        $results = $this->results((new ProductImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('almacen_codigo', $results[0]->errors);
    }
}
