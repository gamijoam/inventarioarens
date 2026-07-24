<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Products\Models\Tag;
use App\Modules\Products\Services\ProductPriceService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductShowAndPricingTest extends TestCase
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

    public function test_show_endpoint_includes_brand_categories_and_tags(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa-show-test']);
        $this->useTenant($tenant);

        $brand = Brand::create(['name' => 'Samsung', 'slug' => 'samsung', 'is_active' => true]);
        $category = Category::create(['name' => 'Celulares', 'slug' => 'celulares', 'is_active' => true, 'sort_order' => 1]);
        $tag = Tag::create(['name' => 'Nuevo', 'slug' => 'nuevo', 'color' => '#ff0000']);

        $product = Product::create([
            'name' => 'Galaxy S24',
            'sku' => 'GAL-S24',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 700,
            'sale_currency' => Product::CURRENCY_USD,
            'brand_id' => $brand->id,
            'is_active' => true,
        ]);
        $product->categories()->attach($category->id, ['tenant_id' => $tenant->id]);
        $product->tags()->attach($tag->id, ['tenant_id' => $tenant->id]);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin.show@test.test',
            'password' => bcrypt('secret123'),
        ]);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        setPermissionsTeamId($tenant->id);
        $role = Role::create([
            'name' => 'Administrador',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $role->syncPermissions(
            Permission::query()->whereIn('name', ['products.view'])->get()
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($tenant->id);
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.brand.id', $brand->id)
            ->assertJsonPath('data.categories.0.id', $category->id)
            ->assertJsonPath('data.tags.0.id', $tag->id);
    }

    public function test_product_rate_type_overrides_product_price_rate_type(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa-rate-test']);
        $this->useTenant($tenant);

        $bcv = ExchangeRateType::create([
            'code' => 'BCV',
            'name' => 'Banco Central',
            'is_default' => true,
            'is_active' => true,
        ]);
        $paralelo = ExchangeRateType::create([
            'code' => 'PARALELO',
            'name' => 'Paralelo',
            'is_default' => false,
            'is_active' => true,
        ]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $bcv->id,
            'base_currency' => ExchangeRate::BASE_USD,
            'quote_currency' => ExchangeRate::QUOTE_VES,
            'rate' => 36.5,
            'effective_at' => now(),
            'is_active' => true,
        ]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $paralelo->id,
            'base_currency' => ExchangeRate::BASE_USD,
            'quote_currency' => ExchangeRate::QUOTE_VES,
            'rate' => 42.0,
            'effective_at' => now(),
            'is_active' => true,
        ]);

        $priceList = PriceList::create([
            'code' => 'MAYOR',
            'name' => 'Precio al mayor',
            'is_default' => true,
            'is_active' => true,
        ]);

        // Producto anclado a PARALELO.
        $product = Product::create([
            'name' => 'Galaxy S24',
            'sku' => 'GAL-S24',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_VES,
            'sale_exchange_rate_type_id' => $paralelo->id,
            'is_active' => true,
        ]);

        // La lista MAYOR tiene su propio precio anclado a BCV.
        ProductPrice::create([
            'product_id' => $product->id,
            'price_list_id' => $priceList->id,
            'price' => 3650,
            'currency' => Product::CURRENCY_VES,
            'exchange_rate_type_id' => $bcv->id,
            'is_active' => true,
        ]);

        $quote = app(ProductPriceService::class)
            ->quote($product, $priceList->id, 'list');

        // La tasa del producto (42.0 PARALELO) debe ganar sobre la de la
        // lista (36.5 BCV). Sin este fix, el POS cotizaba con BCV.
        $this->assertSame($paralelo->id, $quote['exchange_rate_type_id']);
        $this->assertSame('PARALELO', $quote['exchange_rate_type_code']);
        $this->assertSame(42.0, (float) $quote['exchange_rate']);
        $this->assertSame($priceList->id, $quote['price_list_id']);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
