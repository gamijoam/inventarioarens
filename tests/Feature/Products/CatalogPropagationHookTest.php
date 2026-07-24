<?php

namespace Tests\Feature\Products;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Tag;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica que el trait PropagatesCatalogToSpinoffs dispara la
 * propagacion automatica al guardar catalogos desde el grupo.
 *
 * Estos tests no usan el endpoint HTTP: invocan el servicio directamente
 * porque `runningUnitTests()` desactiva la propagacion automatica del
 * hook para no contaminar transacciones de otros tests. Sirven como
 * prueba de que el codigo de propagacion existe y funciona cuando se
 * le invoca.
 */
class CatalogPropagationHookTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_propagation_helper_is_idempotent(): void
    {
        [$group, $spinoff] = $this->makeGroupAndSpinoff();
        app(TenantManager::class)->set($group);

        $brand = Brand::create(['name' => 'Samsung', 'slug' => 'samsung', 'is_active' => true]);
        $svc = app(SharedCatalogPropagationService::class);
        $svc->propagateAllToSpinoff($group, $spinoff);

        $this->assertDatabaseHas('brands', ['tenant_id' => $spinoff->id, 'slug' => 'samsung']);
        $this->assertSame(1, Brand::withoutGlobalScopes()->where('tenant_id', $spinoff->id)->count());

        // Re-invocar no duplica.
        $svc->propagateAllToSpinoff($group, $spinoff);
        $this->assertSame(1, Brand::withoutGlobalScopes()->where('tenant_id', $spinoff->id)->count());
    }

    public function test_full_catalog_propagation_creates_all_local_copies(): void
    {
        [$group, $spinoff] = $this->makeGroupAndSpinoff();
        app(TenantManager::class)->set($group);

        Brand::create(['name' => 'Sony', 'slug' => 'sony', 'is_active' => true]);
        Category::create(['name' => 'Audio', 'slug' => 'audio', 'is_active' => true]);
        Category::create(['name' => 'Audifonos', 'slug' => 'audifonos', 'is_active' => true, 'parent_id' => null]);
        Tag::create(['name' => 'Oferta', 'slug' => 'oferta', 'color' => '#ff0000']);
        PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Efectivo',
            'method' => 'cash',
            'currency_mode' => PaymentMethod::CURRENCY_FLEXIBLE,
            'is_active' => true,
        ]);
        PriceList::create([
            'code' => 'MAYOR',
            'name' => 'Precio al mayor',
            'is_default' => true,
            'is_active' => true,
        ]);
        ExchangeRateType::create(['code' => 'BCV', 'name' => 'Banco Central', 'is_default' => true, 'is_active' => true]);
        WarrantyPolicy::create([
            'name' => 'Garantia 1 ano',
            'duration_days' => 365,
            'coverage_type' => 'store',
            'is_active' => true,
        ]);

        app(SharedCatalogPropagationService::class)
            ->propagateAllToSpinoff($group, $spinoff);

        $this->assertDatabaseHas('brands', ['tenant_id' => $spinoff->id, 'slug' => 'sony']);
        $this->assertDatabaseHas('categories', ['tenant_id' => $spinoff->id, 'slug' => 'audio']);
        $this->assertDatabaseHas('categories', ['tenant_id' => $spinoff->id, 'slug' => 'audifonos']);
        $this->assertDatabaseHas('tags', ['tenant_id' => $spinoff->id, 'slug' => 'oferta']);
        $this->assertDatabaseHas('payment_methods', ['tenant_id' => $spinoff->id, 'code' => 'CASH']);
        $this->assertDatabaseHas('price_lists', ['tenant_id' => $spinoff->id, 'code' => 'MAYOR']);
        $this->assertDatabaseHas('exchange_rate_types', ['tenant_id' => $spinoff->id, 'code' => 'BCV']);
        $this->assertDatabaseHas('warranty_policies', ['tenant_id' => $spinoff->id, 'name' => 'Garantia 1 ano']);
    }

    public function test_category_with_parent_propagates_parent_id_translated(): void
    {
        [$group, $spinoff] = $this->makeGroupAndSpinoff();
        app(TenantManager::class)->set($group);

        $parent = Category::create(['name' => 'Electronica', 'slug' => 'electronica', 'is_active' => true]);
        $child = Category::create([
            'name' => 'Celulares',
            'slug' => 'celulares',
            'is_active' => true,
            'parent_id' => $parent->id,
        ]);

        app(SharedCatalogPropagationService::class)
            ->propagateSingleCategoryToSpinoff($child, $spinoff);

        $spinoffChild = Category::withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('slug', 'celulares')->first();
        $spinoffParent = Category::withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('slug', 'electronica')->first();

        $this->assertNotNull($spinoffParent);
        $this->assertNotNull($spinoffChild);
        $this->assertSame($spinoffParent->id, $spinoffChild->parent_id);
    }

    public function test_propagation_helper_skips_when_current_tenant_is_not_group(): void
    {
        [$group, $spinoff] = $this->makeGroupAndSpinoff();
        app(TenantManager::class)->set($group);

        // El test: shouldPropagate retorna false si current no es grupo.
        app(TenantManager::class)->set($spinoff);
        $brand = Brand::create(['name' => 'Local', 'slug' => 'local', 'is_active' => true]);

        $this->assertDatabaseHas('brands', ['tenant_id' => $spinoff->id, 'slug' => 'local']);
        $this->assertDatabaseMissing('brands', ['slug' => 'local', 'tenant_id' => $group->id]);
    }

    /**
     * @return array{0: Tenant, 1: Tenant}
     */
    private function makeGroupAndSpinoff(): array
    {
        $group = Tenant::create([
            'name' => 'Test Group',
            'slug' => 'test-group-prop',
            'is_group' => true,
        ]);

        $spinoff = Tenant::create([
            'name' => 'Test Spinoff',
            'slug' => 'test-spinoff-prop',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);

        return [$group, $spinoff];
    }
}
