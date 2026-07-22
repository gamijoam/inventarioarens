<?php

namespace Tests\Feature\DataImport;

use App\Modules\DataImport\Importers\PaymentMethodImporter;
use App\Modules\DataImport\Importers\PriceListImporter;
use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceListPaymentImportersTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/pl-test-'.uniqid();
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

    public function test_payment_method_importer_creates_method(): void
    {
        $path = $this->writeCsv('pms.csv', "code,name,method,currency_mode,is_active\nCASH,Efectivo,cash,USD,true\nZELLE,Zelle,zelle,USD,true\n");

        $results = $this->results((new PaymentMethodImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);
        $this->assertDatabaseHas('payment_methods', ['code' => 'CASH', 'method' => 'cash', 'currency_mode' => 'USD']);
        $this->assertDatabaseHas('payment_methods', ['code' => 'ZELLE', 'method' => 'zelle']);
    }

    public function test_payment_method_importer_skips_duplicate_code(): void
    {
        PaymentMethod::create(['code' => 'CASH', 'name' => 'Old', 'method' => 'cash', 'currency_mode' => 'USD']);
        $path = $this->writeCsv('pms.csv', "code,name,method,currency_mode\nCASH,Nuevo Efectivo,cash,USD\n");

        $results = $this->results((new PaymentMethodImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_SKIPPED, $results[0]->status);
        $this->assertDatabaseHas('payment_methods', ['code' => 'CASH', 'name' => 'Old']);
    }

    public function test_payment_method_importer_rejects_invalid_method(): void
    {
        $path = $this->writeCsv('pms.csv', "code,name,method,currency_mode\nBAD,Bad,fakemethod,USD\n");

        $results = $this->results((new PaymentMethodImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('method', $results[0]->errors);
    }

    public function test_payment_method_importer_normalizes_currency_mode_to_uppercase(): void
    {
        $path = $this->writeCsv('pms.csv', "code,name,method,currency_mode\nVES,Pago Movil,mobile_payment,ves\n");

        $results = $this->results((new PaymentMethodImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertDatabaseHas('payment_methods', ['code' => 'VES', 'currency_mode' => 'VES']);
    }

    public function test_price_list_importer_creates_simple_list(): void
    {
        $path = $this->writeCsv('pls.csv', "code,name,is_default,is_active\nMAYORISTA,Precios Mayorista,false,true\n");

        $results = $this->results((new PriceListImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertDatabaseHas('price_lists', ['code' => 'MAYORISTA', 'name' => 'Precios Mayorista']);
    }

    public function test_price_list_importer_attaches_payment_methods(): void
    {
        PaymentMethod::create(['code' => 'CASH', 'name' => 'Efectivo', 'method' => 'cash', 'currency_mode' => 'USD']);
        PaymentMethod::create(['code' => 'PM', 'name' => 'Pago Movil', 'method' => 'mobile_payment', 'currency_mode' => 'VES']);

        $path = $this->writeCsv('pls.csv', "code,name,payment_method_codes\nMAYORISTA,Mayorista,CASH|PM\n");

        $results = $this->results((new PriceListImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $list = PriceList::query()->where('code', 'MAYORISTA')->first();
        $this->assertCount(2, $list->paymentMethods);
    }

    public function test_price_list_importer_creates_prices_for_products(): void
    {
        Product::create(['sku' => 'SKU-1', 'name' => 'Test 1', 'tracking_type' => 'quantity', 'is_active' => true]);
        Product::create(['sku' => 'SKU-2', 'name' => 'Test 2', 'tracking_type' => 'quantity', 'is_active' => true]);

        $innerJson = '[{"sku":"SKU-1","price":10.5,"currency":"USD"},{"sku":"SKU-2","price":250,"currency":"VES"}]';
        $csvCell = '"'.str_replace('"', '""', $innerJson).'"';
        $path = $this->writeCsv('pls.csv', "code;name;prices\nMAYORISTA;Mayorista;{$csvCell}\n");

        $results = $this->results((new PriceListImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status, json_encode($results[0]->errors ?? []));
        $this->assertDatabaseHas('product_prices', ['price' => '10.5000', 'currency' => 'USD']);
        $this->assertDatabaseHas('product_prices', ['price' => '250.0000', 'currency' => 'VES']);
    }

    public function test_price_list_importer_fails_when_payment_method_unknown(): void
    {
        PaymentMethod::create(['code' => 'CASH', 'name' => 'Efectivo', 'method' => 'cash', 'currency_mode' => 'USD']);
        $path = $this->writeCsv('pls.csv', "code,name,payment_method_codes\nMAYORISTA,M,CASH|BOGUS\n");

        $results = $this->results((new PriceListImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('payment_method_codes', $results[0]->errors);
        $this->assertStringContainsString('BOGUS', $results[0]->errors['payment_method_codes']);
    }

    public function test_price_list_importer_fails_when_product_sku_not_found(): void
    {
        $prices = json_encode([['sku' => 'NOPE', 'price' => 10, 'currency' => 'USD']]);
        $path = $this->writeCsv('pls.csv', "code,name,prices\nMAYORISTA,M,{$prices}\n");

        $results = $this->results((new PriceListImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('prices', $results[0]->errors);
    }

    public function test_price_list_importer_invalid_json_prices(): void
    {
        $path = $this->writeCsv('pls.csv', "code,name,prices\nMAYORISTA,M,not-json\n");

        $results = $this->results((new PriceListImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('prices', $results[0]->errors);
    }
}
