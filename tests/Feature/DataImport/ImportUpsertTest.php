<?php

namespace Tests\Feature\DataImport;

use App\Modules\Branches\Models\Branch;
use App\Modules\DataImport\Importers\PriceListImporter;
use App\Modules\DataImport\Importers\ProductImporter;
use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportUpsertTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/upsert-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function writeCsv(string $name, string $content): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return array<int, ImportRowResult>
     */
    private function runImport(string $entity, string $file): array
    {
        $importer = $entity === 'products'
            ? new ProductImporter(app(SharedCatalogPropagationService::class))
            : new PriceListImporter;

        return iterator_to_array($importer->import($file), false);
    }

    public function test_product_importer_updates_existing_product(): void
    {
        $first = $this->runImport('products', $this->writeCsv('p1.csv', "sku,name,base_price\nSKU-UPD,Original,10\n"));
        $this->assertSame(ImportRowResult::STATUS_OK, $first[0]->status);

        $second = $this->runImport('products', $this->writeCsv('p2.csv', "sku,name,base_price\nSKU-UPD,Renombrado,12.5\n"));
        $this->assertSame(ImportRowResult::STATUS_UPDATED, $second[0]->status);
        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-UPD',
            'name' => 'Renombrado',
            'base_price' => '12.5000',
        ]);
    }

    public function test_product_importer_preserves_fields_omitted_from_simple_upload(): void
    {
        Product::create([
            'sku' => 'SKU-SIMPLE',
            'name' => 'Producto existente',
            'description' => 'Descripcion conservada',
            'tracking_type' => 'quantity',
            'unit_of_measure' => 'unit',
            'base_price' => 25,
            'sale_currency' => 'USD',
            'is_active' => true,
        ]);
        $originalPrice = Product::query()->where('sku', 'SKU-SIMPLE')->value('base_price');

        $this->runImport('products', $this->writeCsv(
            'simple-update.csv',
            "sku,name\nSKU-SIMPLE,Producto actualizado\n",
        ));

        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-SIMPLE',
            'name' => 'Producto actualizado',
            'description' => 'Descripcion conservada',
        ]);
        $this->assertSame($originalPrice, Product::query()->where('sku', 'SKU-SIMPLE')->value('base_price'));
    }

    public function test_product_importer_creates_new_category_automatically(): void
    {
        $this->assertDatabaseMissing('categories', ['name' => 'Ferreteria']);

        $res = $this->runImport('products', $this->writeCsv(
            'p-cat-new.csv',
            "sku,name,category,base_price\nSKU-CAT-1,Tornillo,Ferreteria,1.5\n",
        ));

        $this->assertSame(ImportRowResult::STATUS_OK, $res[0]->status);
        $this->assertDatabaseHas('categories', [
            'name' => 'Ferreteria',
            'slug' => 'ferreteria',
        ]);
        $product = Product::query()->where('sku', 'SKU-CAT-1')->first();
        $this->assertNotNull($product);
        $this->assertTrue($product->categories()->where('name', 'Ferreteria')->exists());
    }

    public function test_product_importer_uses_existing_category_by_name_or_slug(): void
    {
        $cat = \App\Modules\Products\Models\Category::create([
            'name' => 'Papeleria',
            'slug' => 'papeleria',
            'is_active' => true,
        ]);

        $res = $this->runImport('products', $this->writeCsv(
            'p-cat-exist.csv',
            "sku,name,categoria,base_price\nSKU-CAT-2,Cuaderno,Papeleria,3.0\n",
        ));

        $this->assertSame(ImportRowResult::STATUS_OK, $res[0]->status);
        $this->assertSame(1, \App\Modules\Products\Models\Category::query()->where('slug', 'papeleria')->count());
        $product = Product::query()->where('sku', 'SKU-CAT-2')->first();
        $this->assertNotNull($product);
        $this->assertTrue($product->categories()->where('categories.id', $cat->id)->exists());
    }

    public function test_product_importer_updates_category_on_existing_product_and_preserves_when_omitted(): void
    {
        $cat1 = \App\Modules\Products\Models\Category::create([
            'name' => 'Cat Uno',
            'slug' => 'cat-uno',
            'is_active' => true,
        ]);

        $this->runImport('products', $this->writeCsv(
            'p-init.csv',
            "sku,name,category,base_price\nSKU-CAT-3,Item 3,Cat Uno,5.0\n",
        ));

        $product = Product::query()->where('sku', 'SKU-CAT-3')->first();
        $this->assertTrue($product->categories()->where('categories.id', $cat1->id)->exists());

        // Update with omitted category column: preserves Cat Uno
        $this->runImport('products', $this->writeCsv(
            'p-upd-nocat.csv',
            "sku,name,base_price\nSKU-CAT-3,Item 3 Renombrado,6.0\n",
        ));
        $product->refresh();
        $this->assertSame('Item 3 Renombrado', $product->name);
        $this->assertTrue($product->categories()->where('categories.id', $cat1->id)->exists());

        // Update with new category: creates Cat Dos and links it
        $this->runImport('products', $this->writeCsv(
            'p-upd-newcat.csv',
            "sku,name,category,base_price\nSKU-CAT-3,Item 3 Renombrado,Cat Dos,6.0\n",
        ));
        $product->refresh();
        $this->assertDatabaseHas('categories', ['name' => 'Cat Dos', 'slug' => 'cat-dos']);
        $this->assertTrue($product->categories()->where('name', 'Cat Dos')->exists());
        $this->assertFalse($product->categories()->where('categories.id', $cat1->id)->exists());
    }

    public function test_product_importer_skips_existing_without_changes(): void
    {
        $this->runImport('products', $this->writeCsv('p1.csv', "sku,name,base_price\nSKU-SAME,Igual,10\n"));

        $second = $this->runImport('products', $this->writeCsv('p2.csv', "sku,name,base_price\nSKU-SAME,Igual,10\n"));
        $this->assertSame(ImportRowResult::STATUS_SKIPPED, $second[0]->status);
    }

    public function test_product_importer_ignores_stock_for_existing_product(): void
    {
        $branch = Branch::create(['code' => 'BR1', 'name' => 'Principal', 'status' => 'active']);
        Warehouse::create(['branch_id' => $branch->id, 'code' => 'W1', 'name' => 'Almacen']);

        $this->runImport('products', $this->writeCsv('p1.csv', "sku,name,base_price\nSKU-ST,Producto,10\n"));

        $second = $this->runImport('products', $this->writeCsv(
            'p2.csv',
            "sku,name,base_price,stock_inicial,almacen_codigo\nSKU-ST,Producto Editado,10,5,W1\n",
        ));

        $this->assertSame(ImportRowResult::STATUS_UPDATED, $second[0]->status);
        $this->assertDatabaseHas('products', ['sku' => 'SKU-ST', 'name' => 'Producto Editado']);
        // El stock NO se aplica al actualizar un producto existente.
        $this->assertDatabaseCount('product_entries', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_price_list_importer_updates_existing_list_and_prices(): void
    {
        Product::create([
            'sku' => 'SKU-PL',
            'name' => 'Producto PL',
            'tracking_type' => 'quantity',
            'base_price' => 10,
            'sale_currency' => 'USD',
            'unit_of_measure' => 'unit',
            'is_active' => true,
        ]);

        $header = "code;name;description;is_default;is_active;sort_order;payment_method_codes;prices\n";
        $prices = fn (float $price): string => '"'
            .str_replace('"', '""', json_encode([['sku' => 'SKU-PL', 'price' => $price]]))
            .'"';

        $create = $this->runImport('price_lists', $this->writeCsv(
            'pl1.csv',
            $header.'ADV;Lista Vieja;;false;true;0;;'.$prices(10)."\n",
        ));
        $this->assertSame(ImportRowResult::STATUS_OK, $create[0]->status);

        $update = $this->runImport('price_lists', $this->writeCsv(
            'pl2.csv',
            $header.'ADV;Lista Nueva;;false;true;0;;'.$prices(15)."\n",
        ));
        $this->assertSame(ImportRowResult::STATUS_UPDATED, $update[0]->status);
        $this->assertDatabaseHas('price_lists', ['code' => 'ADV', 'name' => 'Lista Nueva']);

        $productId = Product::query()->where('sku', 'SKU-PL')->value('id');
        $this->assertDatabaseHas('product_prices', [
            'product_id' => $productId,
            'price' => '15.0000',
        ]);
    }

    public function test_price_list_importer_skips_existing_without_changes(): void
    {
        Product::create([
            'sku' => 'SKU-PL2',
            'name' => 'Producto PL2',
            'tracking_type' => 'quantity',
            'base_price' => 10,
            'sale_currency' => 'USD',
            'unit_of_measure' => 'unit',
            'is_active' => true,
        ]);

        $header = "code;name;description;is_default;is_active;sort_order;payment_method_codes;prices\n";
        $row = $header.'ADV2;Lista Estable;;false;true;0;;'
            .'"'.str_replace('"', '""', json_encode([['sku' => 'SKU-PL2', 'price' => 10]])).'"'."\n";

        $this->runImport('price_lists', $this->writeCsv('pl1.csv', $row));
        $second = $this->runImport('price_lists', $this->writeCsv('pl2.csv', $row));

        $this->assertSame(ImportRowResult::STATUS_SKIPPED, $second[0]->status);
    }
}
