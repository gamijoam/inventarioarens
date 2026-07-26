<?php

namespace Tests\Feature\Products;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedCatalogEntityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciles_warranty_supplier_price_and_rate_copies(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff();
        $this->useTenant($group);

        $warranty = WarrantyPolicy::create([
            'name' => 'Garantia 12 meses',
            'duration_days' => 365,
            'coverage_type' => WarrantyPolicy::COVERAGE_STORE,
            'conditions' => 'Condiciones originales',
            'is_active' => true,
        ]);
        $supplier = Supplier::create([
            'name' => 'Proveedor Central',
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => 'J-12345678-9',
            'is_active' => true,
        ]);
        $rateType = ExchangeRateType::create([
            'code' => 'BCV',
            'name' => 'Banco Central',
            'is_default' => true,
            'is_active' => true,
        ]);
        $rate = ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'base_currency' => ExchangeRate::BASE_USD,
            'quote_currency' => ExchangeRate::QUOTE_VES,
            'rate' => 800,
            'effective_at' => now()->startOfDay(),
            'is_active' => true,
            'source' => 'BCV',
        ]);
        $list = PriceList::create([
            'code' => 'DETAL',
            'name' => 'Precio detal',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => 'Producto compartido',
            'sku' => 'SHARED-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
            'is_catalog_master' => true,
            'warranty_policy_id' => $warranty->id,
        ]);
        ProductPrice::create([
            'product_id' => $product->id,
            'price_list_id' => $list->id,
            'price' => 12.50,
            'currency' => 'USD',
            'exchange_rate_type_id' => $rateType->id,
            'is_active' => true,
        ]);

        app(SharedCatalogPropagationService::class)->propagateAllToSpinoff($group, $spinoff);

        $copyProduct = Product::withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $product->id)
            ->firstOrFail();
        $copyList = PriceList::withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('code', 'DETAL')
            ->firstOrFail();
        $copyRateType = ExchangeRateType::withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('code', 'BCV')
            ->firstOrFail();

        $this->assertDatabaseHas('warranty_policies', [
            'tenant_id' => $spinoff->id,
            'name' => 'Garantia 12 meses',
        ]);
        $this->assertDatabaseHas('suppliers', [
            'tenant_id' => $spinoff->id,
            'document_number' => 'J-12345678-9',
        ]);
        $this->assertDatabaseHas('exchange_rates', [
            'tenant_id' => $spinoff->id,
            'exchange_rate_type_id' => $copyRateType->id,
            'rate' => '800.000000',
        ]);
        $this->assertDatabaseHas('product_prices', [
            'tenant_id' => $spinoff->id,
            'product_id' => $copyProduct->id,
            'price_list_id' => $copyList->id,
            'price' => '12.5000',
            'exchange_rate_type_id' => $copyRateType->id,
        ]);

        $rate->update(['rate' => 825]);
        $productPrice = ProductPrice::query()->where('product_id', $product->id)->firstOrFail();
        $productPrice->update(['price' => 13.75]);
        $warranty->update(['duration_days' => 730]);
        $supplier->update(['phone' => '04140000000']);

        app(SharedCatalogPropagationService::class)->propagateAllToSpinoff($group, $spinoff);

        $this->assertDatabaseHas('exchange_rates', [
            'tenant_id' => $spinoff->id,
            'exchange_rate_type_id' => $copyRateType->id,
            'rate' => '825.000000',
        ]);
        $this->assertDatabaseHas('product_prices', [
            'tenant_id' => $spinoff->id,
            'product_id' => $copyProduct->id,
            'price_list_id' => $copyList->id,
            'price' => '13.7500',
        ]);
        $this->assertDatabaseHas('warranty_policies', [
            'tenant_id' => $spinoff->id,
            'name' => 'Garantia 12 meses',
            'duration_days' => 730,
        ]);
        $this->assertDatabaseHas('suppliers', [
            'tenant_id' => $spinoff->id,
            'document_number' => 'J-12345678-9',
            'phone' => '04140000000',
        ]);
    }

    public function test_reconciliation_deactivates_price_lists_missing_from_master(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff();
        $this->useTenant($spinoff);
        $orphan = PriceList::create([
            'code' => 'PRECIO-VIEJO',
            'name' => 'Lista vieja',
            'is_active' => true,
        ]);

        $this->useTenant($group);
        PriceList::create([
            'code' => 'DETAL',
            'name' => 'Precio detal',
            'is_active' => true,
        ]);

        app(SharedCatalogPropagationService::class)->propagateAllToSpinoff($group, $spinoff);

        $this->assertDatabaseHas('price_lists', [
            'id' => $orphan->id,
            'tenant_id' => $spinoff->id,
            'is_active' => false,
        ]);
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
            'name' => 'Tienda Demo',
            'slug' => 'tienda-demo',
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
