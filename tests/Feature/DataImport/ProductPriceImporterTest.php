<?php

namespace Tests\Feature\DataImport;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\DataImport\Importers\ProductPriceImporter;
use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPriceImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/pp-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        PriceList::create(['code' => 'DETAL', 'name' => 'Detal', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        PriceList::create(['code' => 'MAYOR', 'name' => 'Mayor', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);

        Product::create(['sku' => 'SKU-1', 'name' => 'Test 1', 'tracking_type' => 'quantity', 'is_active' => true, 'base_price' => 10]);
        Product::create(['sku' => 'SKU-2', 'name' => 'Test 2', 'tracking_type' => 'quantity', 'is_active' => true, 'base_price' => 8]);
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

    public function test_creates_price_for_existing_product_and_list(): void
    {
        $path = $this->writeCsv('prices.csv',
            "sku,list_code,price,currency,is_active\n".
            "SKU-1,DETAL,15.50,USD,true\n"
        );

        $results = $this->results((new ProductPriceImporter)->import($path));

        $this->assertCount(1, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertDatabaseHas('product_prices', [
            'price' => '15.5000',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $price = ProductPrice::query()->where('price', 15.50)->first();
        $product = Product::query()->where('sku', 'SKU-1')->first();
        $list = PriceList::query()->where('code', 'DETAL')->first();
        $this->assertSame($product->id, $price->product_id);
        $this->assertSame($list->id, $price->price_list_id);
    }

    public function test_updates_existing_price_without_duplicating(): void
    {
        $product = Product::query()->where('sku', 'SKU-1')->first();
        $list = PriceList::query()->where('code', 'DETAL')->first();
        ProductPrice::create([
            'product_id' => $product->id,
            'price_list_id' => $list->id,
            'price' => 10.00,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $path = $this->writeCsv('prices.csv',
            "sku,list_code,price,currency,is_active\n".
            "SKU-1,DETAL,18.00,USD,false\n"
        );

        $results = $this->results((new ProductPriceImporter)->import($path));

        $this->assertCount(1, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertSame(1, ProductPrice::query()->count());
        $row = ProductPrice::query()->first();
        $this->assertEquals(18.00, (float) $row->price);
        $this->assertFalse((bool) $row->is_active);
    }

    public function test_creates_one_price_per_row_for_same_product_in_different_lists(): void
    {
        $path = $this->writeCsv('prices.csv',
            "sku,list_code,price,currency,is_active\n".
            "SKU-1,DETAL,15.50,USD,true\n".
            "SKU-1,MAYOR,12.00,USD,true\n"
        );

        $results = $this->results((new ProductPriceImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);
        $this->assertSame(2, ProductPrice::query()->count());
    }

    public function test_fails_when_sku_not_found(): void
    {
        $path = $this->writeCsv('prices.csv',
            "sku,list_code,price,currency,is_active\n".
            "NOPE,DETAL,15.50,USD,true\n"
        );

        $results = $this->results((new ProductPriceImporter)->import($path));

        $this->assertCount(1, $results);
        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('sku', $results[0]->errors);
        $this->assertStringContainsString('NOPE', $results[0]->errors['sku']);
    }

    public function test_fails_when_list_code_not_found(): void
    {
        $path = $this->writeCsv('prices.csv',
            "sku,list_code,price,currency,is_active\n".
            "SKU-1,INVENTADA,15.50,USD,true\n"
        );

        $results = $this->results((new ProductPriceImporter)->import($path));

        $this->assertCount(1, $results);
        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('list_code', $results[0]->errors);
        $this->assertStringContainsString('INVENTADA', $results[0]->errors['list_code']);
    }

    public function test_fails_with_invalid_currency(): void
    {
        $path = $this->writeCsv('prices.csv',
            "sku,list_code,price,currency,is_active\n".
            "SKU-1,DETAL,15.50,EUR,true\n"
        );

        $results = $this->results((new ProductPriceImporter)->import($path));

        $this->assertCount(1, $results);
        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('currency', $results[0]->errors);
    }

    public function test_fails_with_negative_or_missing_price(): void
    {
        $path = $this->writeCsv('prices.csv',
            "sku,list_code,price,currency,is_active\n".
            "SKU-1,DETAL,,USD,true\n".
            "SKU-2,DETAL,-5,USD,true\n"
        );

        $results = $this->results((new ProductPriceImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[1]->status);
        $this->assertArrayHasKey('price', $results[0]->errors);
        $this->assertArrayHasKey('price', $results[1]->errors);
    }

    public function test_normalizes_decimal_comma_separator(): void
    {
        $path = $this->writeCsv('prices.csv',
            "sku,list_code,price,currency,is_active\n".
            "SKU-1,DETAL,\"15,50\",USD,true\n"
        );

        $results = $this->results((new ProductPriceImporter)->import($path));

        $this->assertCount(1, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertDatabaseHas('product_prices', [
            'price' => '15.5000',
            'currency' => 'USD',
        ]);
    }

    public function test_assigns_exchange_rate_type_by_code(): void
    {
        ExchangeRateType::create(['code' => 'BCV', 'name' => 'Banco Central', 'is_default' => true, 'is_active' => true]);

        $path = $this->writeCsv('prices.csv',
            "sku,list_code,price,currency,is_active,exchange_rate_type_code\n".
            "SKU-1,DETAL,15.50,USD,true,BCV\n"
        );

        $results = $this->results((new ProductPriceImporter)->import($path));

        $this->assertCount(1, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $rateType = ExchangeRateType::query()->where('code', 'BCV')->first();
        $this->assertDatabaseHas('product_prices', [
            'price' => '15.5000',
            'exchange_rate_type_id' => $rateType->id,
        ]);
    }

    public function test_fails_when_exchange_rate_type_code_unknown(): void
    {
        $path = $this->writeCsv('prices.csv',
            "sku,list_code,price,currency,is_active,exchange_rate_type_code\n".
            "SKU-1,DETAL,15.50,USD,true,BOGUS\n"
        );

        $results = $this->results((new ProductPriceImporter)->import($path));

        $this->assertCount(1, $results);
        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('exchange_rate_type_code', $results[0]->errors);
    }
}
