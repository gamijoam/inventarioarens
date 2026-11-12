<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryCostService;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Products\Models\Product;
use App\Modules\PurchaseReturns\Services\PurchaseReturnService;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Todo movimiento fisico queda valorado (hallazgo A7) y el ajuste rapido de
 * stock pasa por el servicio de inventario: bloqueo, costo, auditoria, sync y
 * permiso de ajuste (hallazgo A8).
 */
class MovementCostAndQuickAdjustTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Warehouse $warehouse;

    private Product $product;

    private Supplier $supplier;

    public function test_adjustments_without_explicit_cost_are_valued_at_the_current_cost(): void
    {
        $this->purchase(10, 5);
        $this->purchase(10, 8);

        $this->product->refresh();
        $out = app(InventoryMovementService::class)->adjustmentOut($this->warehouse, $this->product, 2, $this->user, 'Merma');
        $this->assertEqualsWithDelta(8.0, (float) $out->unit_cost, 0.0001, 'Ultimo costo por defecto.');

        TenantSetting::firstOrCreate(['tenant_id' => $this->tenant->id])->set('company.inventory_costing_method', InventoryCostService::METHOD_WEIGHTED_AVERAGE);
        $in = app(InventoryMovementService::class)->adjustmentIn($this->warehouse, $this->product, 1, $this->user, 'Sobrante');
        $this->assertEqualsWithDelta(6.5, (float) $in->unit_cost, 0.0001, 'Promedio ponderado si esta activo.');
    }

    public function test_reservations_are_not_valued(): void
    {
        $this->purchase(5, 5);

        $this->assertNotNull($this->product->refresh()->last_purchase_cost);
        $reserved = app(InventoryMovementService::class)->reserve($this->warehouse, $this->product, 1, $this->user, 'Apartado');

        $this->assertNull($reserved->unit_cost);
    }

    public function test_purchase_return_is_valued_at_the_cost_of_the_returned_purchase(): void
    {
        $first = $this->purchase(5, 10);
        $this->purchase(5, 20);

        app(PurchaseReturnService::class)->create($this->user, [
            'purchase_order_id' => $first->id,
            'items' => [['purchase_item_id' => $first->items->first()->id, 'quantity' => 2]],
        ]);

        $this->assertEqualsWithDelta(10.0, (float) StockMovement::query()->where('type', 'purchase_return')->sole()->unit_cost, 0.0001);
    }

    public function test_completed_stock_count_adjustments_are_valued_and_queued_for_sync(): void
    {
        $this->purchase(10, 5);
        $service = app(StockCountService::class);
        $count = $service->create($this->tenant, $this->warehouse, ['code' => 'CC-1', 'name' => 'Conteo', 'count_type' => 'full'], $this->user->id);
        $service->snapshot($count);
        $service->start($count->refresh());
        $item = StockCountItem::query()->where('stock_count_id', $count->id)->sole();
        $service->capture($count->refresh(), [$item->id => 7], $this->user->id);

        $service->complete($count->refresh(), $this->user->id);

        $movement = StockMovement::query()->where('reference_type', 'stock_count')->sole();
        $this->assertSame('adjustment_out', $movement->type);
        $this->assertEqualsWithDelta(5.0, (float) $movement->unit_cost, 0.0001);
        $payload = json_decode((string) DB::table('sync_outbox')->where('event_type', 'stock_movement.created')->where('aggregate_id', $movement->id)->value('payload'), true);
        $this->assertSame('adjustment_out', $payload['type']);
        $this->assertEqualsWithDelta(3.0, (float) $payload['quantity'], 0.0001);
    }

    public function test_quick_set_stock_goes_through_the_inventory_service(): void
    {
        $this->purchase(10, 5);

        $this->postJson("/api/inventory-center/products/{$this->product->id}/set-stock", [
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 6,
            'reason' => 'Conteo rapido',
        ])->assertOk();

        $this->assertSame(6.0, (float) StockBalance::query()->where('product_id', $this->product->id)->value('quantity_available'));
        $movement = StockMovement::query()->where('reference_type', 'catalog_quick_stock_set')->sole();
        $this->assertSame('adjustment_out', $movement->type);
        $this->assertEqualsWithDelta(4.0, (float) $movement->quantity, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $movement->unit_cost, 0.0001);
        $this->assertSame($this->user->id, $movement->created_by);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'inventory.movement.created')->where('entity_id', $movement->id)->exists(), 'El ajuste queda auditado.');
        $this->assertTrue(DB::table('sync_outbox')->where('event_type', 'stock_movement.created')->where('aggregate_id', $movement->id)->exists(), 'El ajuste viaja a la nube.');
    }

    public function test_quick_set_stock_can_raise_the_stock(): void
    {
        $this->purchase(10, 5);

        $this->postJson("/api/inventory-center/products/{$this->product->id}/set-stock", [
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 12,
        ])->assertOk();

        $this->assertSame(12.0, (float) StockBalance::query()->where('product_id', $this->product->id)->value('quantity_available'));
        $movement = StockMovement::query()->where('reference_type', 'catalog_quick_stock_set')->sole();
        $this->assertSame('adjustment_in', $movement->type);
        $this->assertEqualsWithDelta(2.0, (float) $movement->quantity, 0.0001);
    }

    public function test_quick_set_stock_requires_the_adjust_permission(): void
    {
        $this->purchase(10, 5);
        $editor = User::factory()->create();
        $editor->tenants()->attach($this->tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Editor catalogo', 'web');
        $role->syncPermissions(['products.view', 'products.update']);
        $editor->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($editor)->postJson("/api/inventory-center/products/{$this->product->id}/set-stock", [
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0,
        ])->assertForbidden();

        $this->assertSame(10.0, (float) StockBalance::query()->where('product_id', $this->product->id)->value('quantity_available'));
    }

    public function test_quick_set_stock_cannot_go_negative_when_the_company_does_not_allow_it(): void
    {
        $this->purchase(3, 5);

        $this->postJson("/api/inventory-center/products/{$this->product->id}/set-stock", [
            'warehouse_id' => $this->warehouse->id,
            'quantity' => -2,
        ])->assertUnprocessable();

        $this->assertSame(3.0, (float) StockBalance::query()->where('product_id', $this->product->id)->value('quantity_available'));
        $this->assertSame(0, StockMovement::query()->where('reference_type', 'catalog_quick_stock_set')->count());
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

        return $service->receive($purchase, $this->user)->load('items');
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Movimientos', 'slug' => 'movimientos-'.uniqid()]);
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
        $this->product = Product::create(['name' => 'Producto', 'sku' => 'MOV-1', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 20, 'sale_currency' => Product::CURRENCY_USD]);
        $this->supplier = Supplier::create(['name' => 'Proveedor', 'document_type' => Supplier::DOCUMENT_J, 'document_number' => '500000005']);
    }
}
