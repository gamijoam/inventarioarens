<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Services\InventoryCostService;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Products\Models\Product;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Metodo de costeo por empresa (hallazgo C6): por defecto "ultimo costo" (toda
 * la mercancia se valora al costo de la ultima compra, practica usual en
 * Venezuela) y, activable, promedio ponderado movil.
 */
class CostingMethodTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Warehouse $warehouse;

    private Product $product;

    private Supplier $supplier;

    public function test_last_cost_is_the_default_method(): void
    {
        $this->assertSame(InventoryCostService::METHOD_LAST_COST, app(InventoryCostService::class)->method());
        $this->getJson('/api/tenant-settings')
            ->assertOk()
            ->assertJsonPath('data.settings.company.inventory_costing_method', InventoryCostService::METHOD_LAST_COST);
    }

    public function test_with_last_cost_the_sale_is_costed_at_the_latest_purchase_cost(): void
    {
        $this->purchase(10, 5);
        $this->purchase(10, 8);

        $sale = $this->sell(10);

        $this->assertEqualsWithDelta(8.0, (float) $sale->items->first()->base_unit_cost, 0.0001);
        $this->assertEqualsWithDelta(8.0, (float) $this->product->refresh()->last_purchase_cost, 0.0001);
    }

    public function test_the_weighted_average_is_maintained_on_every_receipt(): void
    {
        $this->purchase(10, 5);
        $this->assertEqualsWithDelta(5.0, (float) $this->product->refresh()->average_cost, 0.0001);

        $this->purchase(10, 8);

        $this->assertEqualsWithDelta(6.5, (float) $this->product->refresh()->average_cost, 0.0001, '(10x5 + 10x8) / 20');
    }

    public function test_with_weighted_average_the_sale_is_costed_at_the_average(): void
    {
        $this->setMethod(InventoryCostService::METHOD_WEIGHTED_AVERAGE);
        $this->purchase(10, 5);
        $this->purchase(10, 8);

        $sale = $this->sell(10);

        $this->assertEqualsWithDelta(6.5, (float) $sale->items->first()->base_unit_cost, 0.0001);
        $this->assertEqualsWithDelta(65.0, (float) $sale->items->first()->base_unit_cost * 10, 0.0001);
    }

    public function test_the_average_only_blends_the_remaining_stock(): void
    {
        $this->setMethod(InventoryCostService::METHOD_WEIGHTED_AVERAGE);
        $this->purchase(10, 5);
        $this->sell(5);
        $this->purchase(5, 8);

        $this->assertEqualsWithDelta(6.5, (float) $this->product->refresh()->average_cost, 0.0001, '(5 restantes x5 + 5x8) / 10');
        $this->assertEqualsWithDelta(6.5, (float) $this->sell(1)->items->first()->base_unit_cost, 0.0001);
    }

    public function test_a_receipt_without_previous_stock_starts_the_average_at_its_cost(): void
    {
        $this->product->update(['average_cost' => 99, 'last_purchase_cost' => 99]);

        $this->purchase(4, 7);

        $this->assertEqualsWithDelta(7.0, (float) $this->product->refresh()->average_cost, 0.0001, 'Sin existencia previa el promedio no arrastra costos viejos.');
    }

    public function test_the_average_is_weighted_by_quantity(): void
    {
        $this->purchase(10, 5);
        $this->purchase(30, 9);

        $this->assertEqualsWithDelta(8.0, (float) $this->product->refresh()->average_cost, 0.0001, '(10x5 + 30x9) / 40, no el promedio simple de costos');
    }

    public function test_a_receipt_over_negative_stock_restarts_the_average_at_its_cost(): void
    {
        TenantSetting::firstOrCreate(['tenant_id' => $this->tenant->id])->set('company.allow_negative_stock', true);
        $this->purchase(2, 5);
        $this->sell(4);

        $this->purchase(4, 7);

        $this->assertEqualsWithDelta(7.0, (float) $this->product->refresh()->average_cost, 0.0001, 'Con existencia negativa no se mezcla el costo anterior.');
    }

    public function test_an_unknown_stored_method_falls_back_to_last_cost(): void
    {
        $this->setMethod('fifo');

        $this->assertSame(InventoryCostService::METHOD_LAST_COST, app(InventoryCostService::class)->method());
    }

    public function test_rebuilding_the_average_from_history_follows_the_remaining_stock(): void
    {
        $this->purchase(10, 5);
        $this->sell(10);
        $this->purchase(10, 9);
        $this->product->update(['average_cost' => null]);

        $this->assertEqualsWithDelta(9.0, app(InventoryValuationService::class)->recalculate($this->product->refresh()), 0.0001, 'Lo vendido ya no pesa en el promedio.');
    }

    public function test_rebuilding_the_average_over_negative_stock_restarts_at_the_next_cost(): void
    {
        TenantSetting::firstOrCreate(['tenant_id' => $this->tenant->id])->set('company.allow_negative_stock', true);
        $this->purchase(10, 5);
        $this->sell(12);
        $this->purchase(10, 9);

        $this->assertEqualsWithDelta(9.0, app(InventoryValuationService::class)->recalculate($this->product->refresh()), 0.0001);
    }

    public function test_manual_cost_is_used_when_there_are_no_purchases(): void
    {
        $this->product->update(['last_purchase_cost' => 12, 'average_cost' => null]);
        $this->stockWithoutCost(3);

        $this->assertEqualsWithDelta(12.0, (float) $this->sell(1)->items->first()->base_unit_cost, 0.0001);

        $this->setMethod(InventoryCostService::METHOD_WEIGHTED_AVERAGE);
        $this->assertEqualsWithDelta(12.0, (float) $this->sell(1)->items->first()->base_unit_cost, 0.0001, 'Sin promedio calculado se usa el costo registrado.');
    }

    public function test_admin_can_switch_the_method_and_invalid_values_are_rejected(): void
    {
        $this->patchJson('/api/tenant-settings', ['settings' => ['company' => ['inventory_costing_method' => 'weighted_average']]])
            ->assertOk()
            ->assertJsonPath('data.settings.company.inventory_costing_method', 'weighted_average');
        app(TenantManager::class)->set($this->tenant);
        $this->assertSame(InventoryCostService::METHOD_WEIGHTED_AVERAGE, app(InventoryCostService::class)->method());

        $this->patchJson('/api/tenant-settings', ['settings' => ['company' => ['inventory_costing_method' => 'fifo']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.company.inventory_costing_method');
    }

    private function setMethod(string $method): void
    {
        $setting = TenantSetting::firstOrCreate(['tenant_id' => $this->tenant->id]);
        $setting->set('company.inventory_costing_method', $method);
    }

    private function purchase(float $quantity, float $unitCost): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);
        $purchase = $service->createDraft($this->user, [
            'supplier_id' => $this->supplier->id,
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'items' => [[
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]],
        ]);

        return $service->receive($purchase, $this->user);
    }

    private function sell(float $quantity): Sale
    {
        $sale = app(SaleService::class)->createDraft($this->user, [[
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
        ]]);

        return app(SaleService::class)->confirm($sale, $this->user)->load('items');
    }

    private function stockWithoutCost(float $quantity): void
    {
        app(InventoryMovementService::class)->adjustmentIn($this->warehouse, $this->product, $quantity, $this->user, 'Inventario inicial');
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Costeo', 'slug' => 'costeo-'.uniqid()]);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $this->user->assignRole($role);
        $this->actingAs($this->user)->withHeader('X-Tenant', $this->tenant->slug);

        $rateType = ExchangeRateType::create(['name' => 'BCV', 'code' => 'BCV', 'is_default' => true]);
        ExchangeRate::create(['exchange_rate_type_id' => $rateType->id, 'rate' => 100, 'effective_at' => now()->subMinute(), 'is_active' => true]);
        $branch = Branch::create(['name' => 'Principal', 'code' => 'PRI']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'ALM']);
        $this->product = Product::create(['name' => 'Producto', 'sku' => 'COST-1', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 20, 'sale_currency' => Product::CURRENCY_USD]);
        $this->supplier = Supplier::create(['name' => 'Proveedor', 'document_type' => Supplier::DOCUMENT_J, 'document_number' => '400000004']);
    }
}
