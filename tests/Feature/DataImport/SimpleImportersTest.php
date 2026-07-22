<?php

namespace Tests\Feature\DataImport;

use App\Modules\Branches\Models\Branch;
use App\Modules\DataImport\Importers\BranchImporter;
use App\Modules\DataImport\Importers\BrandImporter;
use App\Modules\DataImport\Importers\CategoryImporter;
use App\Modules\DataImport\Importers\TagImporter;
use App\Modules\DataImport\Importers\WarehouseImporter;
use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\Products\Models\Category;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimpleImportersTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/import-test-'.uniqid();
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

    private function resultsToArray(\Generator $gen): array
    {
        return iterator_to_array($gen, false);
    }

    public function test_branch_importer_creates_valid_branch(): void
    {
        $path = $this->writeCsv('branches.csv', "code,name,status\nMAIN,Sucursal Principal,active\nNORTE,Sucursal Norte,inactive\n");

        $results = $this->resultsToArray((new BranchImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertSame('MAIN', $results[0]->naturalKey);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);

        $this->assertDatabaseHas('branches', ['code' => 'MAIN', 'name' => 'Sucursal Principal', 'status' => 'active']);
        $this->assertDatabaseHas('branches', ['code' => 'NORTE', 'name' => 'Sucursal Norte', 'status' => 'inactive']);
    }

    public function test_branch_importer_skips_duplicate(): void
    {
        Branch::create(['code' => 'EXISTING', 'name' => 'Old', 'status' => 'active']);
        $path = $this->writeCsv('branches.csv', "code,name\nEXISTING,New\nNEW,Brand new\n");

        $results = $this->resultsToArray((new BranchImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_SKIPPED, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);
        $this->assertDatabaseHas('branches', ['code' => 'EXISTING', 'name' => 'Old']);
        $this->assertDatabaseHas('branches', ['code' => 'NEW']);
    }

    public function test_branch_importer_fails_on_missing_required_fields(): void
    {
        $path = $this->writeCsv('branches.csv', "code,name\n,Sin codigo\nINVALID CODE,BAD\n");

        $results = $this->resultsToArray((new BranchImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('code', $results[0]->errors);
        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[1]->status);
        $this->assertArrayHasKey('code', $results[1]->errors);
    }

    public function test_warehouse_importer_requires_existing_branch(): void
    {
        $path = $this->writeCsv('warehouses.csv', "code,name,branch_code\nW1,Almacen 1,MAIN\n");

        $results = $this->resultsToArray((new WarehouseImporter)->import($path));

        $this->assertCount(1, $results);
        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('branch_code', $results[0]->errors);
        $this->assertStringContainsString("'MAIN' no existe", $results[0]->errors['branch_code']);
    }

    public function test_warehouse_importer_creates_with_existing_branch(): void
    {
        $branch = Branch::create(['code' => 'MAIN', 'name' => 'Main', 'status' => 'active']);
        $path = $this->writeCsv('warehouses.csv', "code,name,branch_code\nW1,Almacen Principal,MAIN\n");

        $results = $this->resultsToArray((new WarehouseImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertDatabaseHas('warehouses', [
            'code' => 'W1',
            'name' => 'Almacen Principal',
            'branch_id' => $branch->id,
        ]);
    }

    public function test_brand_importer_creates_brand(): void
    {
        $path = $this->writeCsv('brands.csv', "slug,name,description\nsamsung,Samsung,Marca coreana\nxiaomi,Xiaomi,\n");

        $results = $this->resultsToArray((new BrandImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);
        $this->assertDatabaseHas('brands', ['slug' => 'samsung', 'name' => 'Samsung']);
        $this->assertDatabaseHas('brands', ['slug' => 'xiaomi', 'description' => null]);
    }

    public function test_brand_importer_rejects_invalid_slug(): void
    {
        $path = $this->writeCsv('brands.csv', "slug,name\nINVALID_SLUG,X\nok-slug,OK\n");

        $results = $this->resultsToArray((new BrandImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);
    }

    public function test_category_importer_supports_parent_reference(): void
    {
        $path = $this->writeCsv('categories.csv', "slug,name,parent_slug\nelectronica,Electronica,\ncelulares,Celulares,electronica\n");

        $results = $this->resultsToArray((new CategoryImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[1]->status);

        $parent = Category::query()->where('slug', 'electronica')->first();
        $child = Category::query()->where('slug', 'celulares')->first();
        $this->assertNotNull($parent);
        $this->assertNotNull($child);
        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_category_importer_fails_when_parent_missing(): void
    {
        $path = $this->writeCsv('categories.csv', "slug,name,parent_slug\norphan,Huerfano,fantasma\n");

        $results = $this->resultsToArray((new CategoryImporter)->import($path));

        $this->assertCount(1, $results);
        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[0]->status);
        $this->assertArrayHasKey('parent_slug', $results[0]->errors);
    }

    public function test_tag_importer_creates_with_color(): void
    {
        $path = $this->writeCsv('tags.csv', "slug,name,color\nnuevo,Nuevo,#00FF00\noferta,Oferta,#FF0000\n");

        $results = $this->resultsToArray((new TagImporter)->import($path));

        $this->assertCount(2, $results);
        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertDatabaseHas('tags', ['slug' => 'nuevo', 'color' => '#00FF00']);
        $this->assertDatabaseHas('tags', ['slug' => 'oferta', 'color' => '#FF0000']);
    }

    public function test_tag_importer_rejects_invalid_color(): void
    {
        $path = $this->writeCsv('tags.csv', "slug,name,color\nok,Ok,#FF0000\nbad,Bad,red\n");

        $results = $this->resultsToArray((new TagImporter)->import($path));

        $this->assertSame(ImportRowResult::STATUS_OK, $results[0]->status);
        $this->assertSame(ImportRowResult::STATUS_FAILED, $results[1]->status);
        $this->assertArrayHasKey('color', $results[1]->errors);
    }
}
