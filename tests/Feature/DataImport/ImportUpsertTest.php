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
