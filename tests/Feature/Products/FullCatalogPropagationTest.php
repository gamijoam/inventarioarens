<?php

namespace Tests\Feature\Products;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\Tag;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FullCatalogPropagationTest extends TestCase
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

    public function test_propagating_all_to_spinoff_clones_every_catalog_entity(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff();
        $this->useTenant($group);

        $brand = Brand::create(['name' => 'Samsung', 'slug' => 'samsung', 'is_active' => true]);
        $parentCategory = Category::create(['name' => 'Electronica', 'slug' => 'electronica', 'is_active' => true]);
        $childCategory = Category::create(['name' => 'Celulares', 'slug' => 'celulares', 'is_active' => true, 'parent_id' => $parentCategory->id]);
        $tag = Tag::create(['name' => 'Nuevo', 'slug' => 'nuevo', 'color' => '#ff0000']);
        $paymentMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Efectivo',
            'method' => 'cash',
            'currency_mode' => PaymentMethod::CURRENCY_FLEXIBLE,
            'is_active' => true,
        ]);
        $priceList = PriceList::create([
            'code' => 'MAYOR',
            'name' => 'Precio al mayor',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $priceList->paymentMethods()->attach($paymentMethod->id, ['tenant_id' => $group->id]);

        $rateType = ExchangeRateType::create(['code' => 'BCV', 'name' => 'Banco Central', 'is_default' => true, 'is_active' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'base_currency' => ExchangeRate::BASE_USD,
            'quote_currency' => ExchangeRate::QUOTE_VES,
            'rate' => 36.5,
            'effective_at' => now(),
            'is_active' => true,
        ]);

        $warrantyPolicy = WarrantyPolicy::create([
            'name' => 'Garantia 6 meses',
            'duration_days' => 180,
            'coverage_type' => 'store',
            'is_active' => true,
        ]);

        $master = Product::create([
            'name' => 'Galaxy S24',
            'sku' => 'GAL-S24',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 700,
            'sale_currency' => Product::CURRENCY_USD,
            'brand_id' => $brand->id,
            'warranty_policy_id' => $warrantyPolicy->id,
            'sale_exchange_rate_type_id' => $rateType->id,
            'is_catalog_master' => true,
        ]);
        $master->categories()->attach($childCategory->id, ['tenant_id' => $group->id]);
        $master->tags()->attach($tag->id, ['tenant_id' => $group->id]);

        app(SharedCatalogPropagationService::class)->propagateAllToSpinoff($group, $spinoff);

        $this->assertDatabaseHas('brands', ['tenant_id' => $spinoff->id, 'slug' => 'samsung']);
        $this->assertDatabaseHas('categories', ['tenant_id' => $spinoff->id, 'slug' => 'electronica', 'parent_id' => null]);
        $spinoffChild = Category::withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('slug', 'celulares')
            ->first();
        $this->assertNotNull($spinoffChild);
        $spinoffParent = Category::withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('slug', 'electronica')
            ->first();
        $this->assertSame($spinoffParent->id, $spinoffChild->parent_id);
        $this->assertDatabaseHas('tags', ['tenant_id' => $spinoff->id, 'slug' => 'nuevo']);
        $this->assertDatabaseHas('payment_methods', ['tenant_id' => $spinoff->id, 'code' => 'CASH']);
        $this->assertDatabaseHas('price_lists', ['tenant_id' => $spinoff->id, 'code' => 'MAYOR']);
        $this->assertDatabaseHas('exchange_rate_types', ['tenant_id' => $spinoff->id, 'code' => 'BCV']);
        $this->assertDatabaseHas('exchange_rates', ['tenant_id' => $spinoff->id, 'base_currency' => 'USD', 'rate' => '36.500000']);
        $this->assertDatabaseHas('warranty_policies', ['tenant_id' => $spinoff->id, 'name' => 'Garantia 6 meses']);
        $this->assertDatabaseHas('products', [
            'tenant_id' => $spinoff->id,
            'catalog_product_id' => $master->id,
            'is_catalog_master' => false,
            'sku' => 'GAL-S24',
        ]);

        $spinoffProduct = Product::withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $master->id)
            ->first();
        $spinoffBrandId = Brand::withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('slug', 'samsung')->value('id');
        $spinoffPaymentMethodId = PaymentMethod::withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('code', 'CASH')->value('id');
        $spinoffPriceListId = PriceList::withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('code', 'MAYOR')->value('id');
        $spinoffChildId = $spinoffChild->id;

        $this->assertSame($spinoffBrandId, $spinoffProduct->brand_id);
        $this->assertSame('BCV', ExchangeRateType::withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('code', 'BCV')
            ->value('code'));
        $this->assertContains($spinoffChildId, $spinoffProduct->categories->pluck('id')->all());
        $this->assertContains(
            Tag::withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('slug', 'nuevo')->value('id'),
            $spinoffProduct->tags->pluck('id')->all(),
        );

        $this->assertDatabaseHas('price_list_payment_method', [
            'price_list_id' => $spinoffPriceListId,
            'payment_method_id' => $spinoffPaymentMethodId,
            'tenant_id' => $spinoff->id,
        ]);
    }

    public function test_propagating_all_is_idempotent(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff();
        $this->useTenant($group);

        Brand::create(['name' => 'LG', 'slug' => 'lg', 'is_active' => true]);
        Product::create([
            'name' => 'OLED TV',
            'sku' => 'OLED-TV',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 1500,
            'sale_currency' => Product::CURRENCY_USD,
            'is_catalog_master' => true,
        ]);

        $svc = app(SharedCatalogPropagationService::class);
        $svc->propagateAllToSpinoff($group, $spinoff);
        $svc->propagateAllToSpinoff($group, $spinoff);
        $svc->propagateAllToSpinoff($group, $spinoff);

        $this->assertSame(1, Brand::withoutGlobalScopes()->where('tenant_id', $spinoff->id)->count());
        $this->assertSame(1, Product::withoutGlobalScopes()->where('tenant_id', $spinoff->id)->count());
    }

    public function test_referenced_catalog_propagation_runs_when_creating_master_with_brand(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff();
        $this->useTenant($group);

        $brand = Brand::create(['name' => 'Sony', 'slug' => 'sony', 'is_active' => true]);

        $master = Product::create([
            'name' => 'PS5',
            'sku' => 'PS5',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
            'brand_id' => $brand->id,
            'is_catalog_master' => true,
        ]);

        // Como Product::create no pasa por el controller, llamamos
        // manualmente la propagacion del catalogo referenciado para
        // simular el mismo flujo que dispararia ProductController::store.
        $svc = app(SharedCatalogPropagationService::class);
        $svc->propagateMaster($master->fresh());
        $svc->propagateReferencedCatalogForMaster($master->fresh());

        $this->assertDatabaseHas('brands', ['tenant_id' => $spinoff->id, 'slug' => 'sony']);
        $this->assertDatabaseHas('products', [
            'tenant_id' => $spinoff->id,
            'catalog_product_id' => $master->id,
        ]);

        $spinoffProduct = Product::withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $master->id)
            ->first();
        $spinoffBrandId = Brand::withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('slug', 'sony')->value('id');
        $this->assertSame($spinoffBrandId, $spinoffProduct->brand_id);
    }

    /**
     * @return array{0: Tenant, 1: Tenant}
     */
    private function createGroupWithSpinoff(): array
    {
        $group = Tenant::create([
            'name' => 'Grupo Demo',
            'slug' => 'grupo-demo',
            'is_group' => true,
        ]);

        $spinoff = Tenant::create([
            'name' => 'Spinoff Demo',
            'slug' => 'spinoff-demo',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);

        app(TenantManager::class)->set($group);
        setPermissionsTeamId($group->id);

        return [$group, $spinoff];
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
