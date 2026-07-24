<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica que el campo `min_stock`, `max_stock` y `reorder_quantity`
 * del producto maestro se propagan a las copias en cada spinoff.
 */
class ProductStockPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_fields_are_in_master_fields_constant(): void
    {
        $this->assertContains('min_stock', Product::MASTER_FIELDS);
        $this->assertContains('max_stock', Product::MASTER_FIELDS);
        $this->assertContains('reorder_quantity', Product::MASTER_FIELDS);
    }

    public function test_creating_master_propagates_stock_to_spinoff_copy(): void
    {
        [$group, $spinoff] = $this->makeGroupAndSpinoff();
        $this->useTenant($group);

        $master = Product::create([
            'name' => 'Cepillo dental',
            'sku' => 'CEP-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 5,
            'sale_currency' => Product::CURRENCY_USD,
            'min_stock' => 3,
            'max_stock' => 50,
            'reorder_quantity' => 10,
            'is_catalog_master' => true,
        ]);

        app(SharedCatalogPropagationService::class)->propagateAllToSpinoff($group, $spinoff);

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $master->id)
            ->first();

        $this->assertNotNull($copy);
        $this->assertSame('3.0000', $copy->min_stock);
        $this->assertSame('50.0000', $copy->max_stock);
        $this->assertSame('10.0000', $copy->reorder_quantity);
    }

    public function test_updating_master_propagates_stock_changes_to_copies(): void
    {
        [$group, $spinoff] = $this->makeGroupAndSpinoff();
        $this->useTenant($group);

        $master = Product::create([
            'name' => 'Cepillo dental',
            'sku' => 'CEP-002',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 5,
            'sale_currency' => Product::CURRENCY_USD,
            'min_stock' => 1,
            'max_stock' => 10,
            'reorder_quantity' => 2,
            'is_catalog_master' => true,
        ]);

        $svc = app(SharedCatalogPropagationService::class);
        $svc->propagateAllToSpinoff($group, $spinoff);

        // Admin sube el stock minimo en el maestro.
        $master->update(['min_stock' => 5, 'max_stock' => 100, 'reorder_quantity' => 20]);

        // Llamamos al mismo flujo que usa ProductController::update.
        $svc->syncMasterFieldsToCopies($master->fresh());

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $master->id)
            ->first();

        $this->assertSame('5.0000', $copy->min_stock);
        $this->assertSame('100.0000', $copy->max_stock);
        $this->assertSame('20.0000', $copy->reorder_quantity);
    }

    /**
     * @return array{0: Tenant, 1: Tenant}
     */
    private function makeGroupAndSpinoff(): array
    {
        $group = Tenant::create([
            'name' => 'Grupo Stock',
            'slug' => 'grupo-stock',
            'is_group' => true,
        ]);

        $spinoff = Tenant::create([
            'name' => 'Spinoff Stock',
            'slug' => 'spinoff-stock',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);

        return [$group, $spinoff];
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
