<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\PurchaseReturns\Services\PurchaseReturnService;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use App\Modules\SalesReturns\Models\SalesReturnItem;
use App\Modules\SalesReturns\Services\SalesReturnService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Las devoluciones de venta y de compra mueven el saldo de la variante
 * (talla/color) correcta, no el saldo generico del producto (hallazgo A4).
 */
class ReturnVariantStockTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Warehouse $warehouse;

    private Product $product;

    private ProductVariant $red;

    private ProductVariant $blue;

    public function test_processed_sale_return_restores_the_sold_variant(): void
    {
        $this->stock($this->red, 5);
        $sale = $this->sale($this->red, 2);
        $this->assertSame(3.0, $this->available($this->red));

        $this->processReturn($sale, 1);

        $this->assertSame(4.0, $this->available($this->red), 'Vuelve a la variante vendida.');
        $this->assertSame(0.0, $this->available(null), 'No aparece stock generico sin variante.');
        $movement = StockMovement::query()->where('type', 'sale_return')->sole();
        $this->assertSame($this->red->id, $movement->product_variant_id);
    }

    public function test_damaged_sale_return_goes_to_the_variant_damaged_balance(): void
    {
        $this->stock($this->red, 5);
        $sale = $this->sale($this->red, 2);

        $this->processReturn($sale, 1, SalesReturnItem::CONDITION_DAMAGED);

        $balance = $this->balance($this->red);
        $this->assertSame(1.0, (float) $balance->quantity_damaged);
        $this->assertSame(3.0, (float) $balance->quantity_available);
        $this->assertNull($this->balance(null));
    }

    public function test_purchase_return_takes_stock_from_the_purchased_variant(): void
    {
        $purchase = $this->purchase($this->blue, 4);
        $this->assertSame(4.0, $this->available($this->blue));

        app(PurchaseReturnService::class)->create($this->user, [
            'purchase_order_id' => $purchase->id,
            'items' => [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 3]],
        ]);

        $this->assertSame(1.0, $this->available($this->blue));
        $movement = StockMovement::query()->where('type', 'purchase_return')->sole();
        $this->assertSame($this->blue->id, $movement->product_variant_id);
    }

    public function test_purchase_return_sync_payload_carries_the_variant_and_the_cloud_moves_it(): void
    {
        $purchase = $this->purchase($this->blue, 4);
        app(PurchaseReturnService::class)->create($this->user, [
            'purchase_order_id' => $purchase->id,
            'items' => [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 3]],
        ]);
        $payload = json_decode((string) DB::table('sync_outbox')->where('event_type', 'purchase_return.created')->value('payload'), true);
        $this->assertSame('ROJO-AZUL-B', $payload['items'][0]['product_variant_sku']);
        $this->assertSame('Azul', $payload['items'][0]['product_variant_color']);

        DB::table('purchase_return_items')->delete();
        DB::table('purchase_returns')->delete();
        StockMovement::query()->where('type', 'purchase_return')->delete();
        StockBalance::query()->where('product_variant_id', $this->blue->id)->update(['quantity_available' => 4]);
        $payload['source_node_code'] = 'POS-LOCAL-1';
        DB::table('sync_inbox')->insert([
            'tenant_id' => $this->tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'purchase_return.created',
            'aggregate_type' => 'purchase_return',
            'aggregate_id' => 1,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncEventApplier::class)->applyPending($this->tenant, 10);

        $this->assertSame('applied', DB::table('sync_inbox')->where('event_type', 'purchase_return.created')->value('status'));
        $this->assertSame(1.0, $this->available($this->blue));
        $this->assertSame($this->blue->id, StockMovement::query()->where('type', 'purchase_return')->sole()->product_variant_id);
        $this->assertSame(PurchaseReturn::query()->sole()->id, StockMovement::query()->where('type', 'purchase_return')->sole()->reference_id);
    }

    private function available(?ProductVariant $variant): float
    {
        return (float) ($this->balance($variant)?->quantity_available ?? 0);
    }

    private function balance(?ProductVariant $variant): ?StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->when($variant, fn ($q) => $q->where('product_variant_id', $variant->id), fn ($q) => $q->whereNull('product_variant_id'))
            ->first();
    }

    private function stock(ProductVariant $variant, float $quantity): void
    {
        app(InventoryMovementService::class)->purchase(
            warehouse: $this->warehouse,
            product: $this->product,
            quantity: $quantity,
            unitCost: 10,
            createdBy: $this->user,
            productVariantId: $variant->id,
        );
    }

    private function sale(ProductVariant $variant, float $quantity): Sale
    {
        $sale = app(SaleService::class)->createDraft($this->user, [[
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
        ]]);

        return app(SaleService::class)->confirm($sale, $this->user)->load('items');
    }

    private function processReturn(Sale $sale, float $quantity, string $condition = SalesReturnItem::CONDITION_SELLABLE): void
    {
        $service = app(SalesReturnService::class);
        $return = $service->create($this->user, [
            'sale_id' => $sale->id,
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => $quantity, 'condition' => $condition]],
        ]);
        $service->approve($return, $this->user);
        $service->process($return, $this->user, ['refund_mode' => 'none']);
    }

    private function purchase(ProductVariant $variant, float $quantity): PurchaseOrder
    {
        $supplier = Supplier::create(['name' => 'Proveedor', 'document_type' => Supplier::DOCUMENT_J, 'document_number' => '300000003']);
        $service = app(PurchaseOrderService::class);
        $purchase = $service->createDraft($this->user, [
            'supplier_id' => $supplier->id,
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'items' => [[
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $this->product->id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'unit_cost' => 10,
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

        $this->tenant = Tenant::create(['name' => 'Variantes', 'slug' => 'variantes-'.uniqid()]);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $this->user->assignRole($role);

        $rateType = ExchangeRateType::create(['name' => 'BCV', 'code' => 'BCV', 'is_default' => true]);
        ExchangeRate::create(['exchange_rate_type_id' => $rateType->id, 'rate' => 100, 'effective_at' => now()->subMinute(), 'is_active' => true]);
        $branch = Branch::create(['name' => 'Principal', 'code' => 'PRI']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'ALM']);
        $this->product = Product::create(['name' => 'Franela', 'sku' => 'ROJO-AZUL', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 20, 'sale_currency' => Product::CURRENCY_USD]);
        $this->red = ProductVariant::create(['product_id' => $this->product->id, 'color' => 'Rojo', 'sku_variant' => 'ROJO-AZUL-R']);
        $this->blue = ProductVariant::create(['product_id' => $this->product->id, 'color' => 'Azul', 'sku_variant' => 'ROJO-AZUL-B']);
    }
}
