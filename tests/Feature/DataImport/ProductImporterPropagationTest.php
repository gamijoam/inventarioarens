<?php

namespace Tests\Feature\DataImport;

use App\Modules\DataImport\Importers\ProductImporter;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\Tag;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImporterPropagationTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/importer-prop-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        $group = Tenant::create([
            'name' => 'Grupo Demo',
            'slug' => 'grupo-demo',
            'is_group' => true,
        ]);

        Tenant::create([
            'name' => 'Spinoff Demo',
            'slug' => 'spinoff-demo',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);

        Tenant::create([
            'name' => 'Spinoff Norte',
            'slug' => 'spinoff-norte',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);

        app(TenantManager::class)->set($group);
        setPermissionsTeamId($group->id);
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

    public function test_imported_master_product_is_replicated_to_every_spinoff(): void
    {
        $path = $this->writeCsv('products.csv',
            "sku,name,base_price\n".
            "SKU-IMP-01,Camisa Negra,15.50\n"
        );

        $importer = new ProductImporter(app(SharedCatalogPropagationService::class));
        $results = $this->collectResults($importer->import($path));

        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]->status);

        $master = Product::query()->where('sku', 'SKU-IMP-01')->first();
        $this->assertNotNull($master);
        $this->assertTrue($master->isCatalogMaster());

        $this->assertDatabaseHas('products', [
            'tenant_id' => $this->groupId(),
            'sku' => 'SKU-IMP-01',
            'is_catalog_master' => true,
        ]);

        $spinoffs = Tenant::query()->where('parent_id', $this->groupId())->get();
        $this->assertCount(2, $spinoffs);

        foreach ($spinoffs as $spinoff) {
            $copy = Product::withoutGlobalScopes()
                ->where('tenant_id', $spinoff->id)
                ->where('catalog_product_id', $master->id)
                ->first();

            $this->assertNotNull($copy, "Spinoff {$spinoff->slug} debe tener la copia del maestro");
            $this->assertSame('Camisa Negra', $copy->name);
            $this->assertEquals(15.50, (float) $copy->base_price);
            $this->assertFalse($copy->isCatalogMaster());
            $this->assertTrue($copy->isCatalogCopy());
        }
    }

    public function test_imported_master_propagates_referenced_brand_to_spinoffs(): void
    {
        Brand::create(['name' => 'Levis', 'slug' => 'levis', 'is_active' => true]);

        $path = $this->writeCsv('products.csv',
            "sku,name,brand_slug,base_price\n".
            "SKU-IMP-02,Jean Azul,levis,25.00\n"
        );

        $importer = new ProductImporter(app(SharedCatalogPropagationService::class));
        $results = $this->collectResults($importer->import($path));

        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]->status);

        $spinoffs = Tenant::query()->where('parent_id', $this->groupId())->get();
        foreach ($spinoffs as $spinoff) {
            $this->assertDatabaseHas('brands', [
                'tenant_id' => $spinoff->id,
                'slug' => 'levis',
            ]);
        }
    }

    public function test_imported_master_propagates_referenced_categories_and_tags_to_spinoffs(): void
    {
        $parentCat = Category::create(['name' => 'Ropa', 'slug' => 'ropa', 'is_active' => true]);
        Category::create([
            'name' => 'Camisas',
            'slug' => 'camisas',
            'is_active' => true,
            'parent_id' => $parentCat->id,
        ]);
        Tag::create(['name' => 'Promo', 'slug' => 'promo', 'color' => '#00ff00']);

        $path = $this->writeCsv('products.csv',
            "sku,name,category_slugs,tag_slugs,base_price\n".
            "SKU-IMP-03,Camisa Promo,ropa|camisas,promo,18.00\n"
        );

        $importer = new ProductImporter(app(SharedCatalogPropagationService::class));
        $results = $this->collectResults($importer->import($path));

        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]->status);

        $spinoffs = Tenant::query()->where('parent_id', $this->groupId())->get();
        foreach ($spinoffs as $spinoff) {
            $this->assertDatabaseHas('categories', ['tenant_id' => $spinoff->id, 'slug' => 'ropa']);
            $this->assertDatabaseHas('categories', ['tenant_id' => $spinoff->id, 'slug' => 'camisas']);
            $this->assertDatabaseHas('tags', ['tenant_id' => $spinoff->id, 'slug' => 'promo']);
        }
    }

    public function test_imported_product_in_standalone_tenant_does_not_propagate(): void
    {
        Tenant::query()->where('slug', 'grupo-demo')->update(['is_group' => false]);
        $this->useTenant(Tenant::query()->where('slug', 'grupo-demo')->first());

        $path = $this->writeCsv('products.csv',
            "sku,name,base_price\n".
            "SKU-LOCAL-01,Producto Local,10.00\n"
        );

        $importer = new ProductImporter(app(SharedCatalogPropagationService::class));
        $results = $this->collectResults($importer->import($path));

        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]->status);

        $product = Product::query()->where('sku', 'SKU-LOCAL-01')->first();
        $this->assertFalse((bool) $product->is_catalog_master);

        $spinoffs = Tenant::query()->where('parent_id', $this->groupId())->get();
        foreach ($spinoffs as $spinoff) {
            $this->assertDatabaseMissing('products', [
                'tenant_id' => $spinoff->id,
                'sku' => 'SKU-LOCAL-01',
            ]);
        }
    }

    private function writeCsv(string $name, string $content): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    private function collectResults(\Generator $gen): array
    {
        return iterator_to_array($gen, false);
    }

    private function groupId(): int
    {
        return (int) Tenant::query()->where('slug', 'grupo-demo')->value('id');
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
