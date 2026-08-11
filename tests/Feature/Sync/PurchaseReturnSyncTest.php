<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\PurchaseReturns\Services\PurchaseReturnService;
use App\Modules\Purchases\Models\PurchaseItem;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica que las devoluciones de compra (purchase_return) emiten eventos de
 * sync y que la nube los aplica (salida de stock + seriales removed + CxP),
 * garantizando sincronizacion bidireccional local<->nube.
 */
class PurchaseReturnSyncTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $branch = Branch::create(['name' => 'B', 'code' => 'B1']);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W1',
            'code' => 'WH-01',
        ]);
        $product = Product::create([
            'name' => 'P',
            'sku' => 'TEST-SKU-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10.0,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $supplier = Supplier::create([
            'name' => 'Proveedor Test',
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => 'J-11111111-1',
        ]);

        return [$tenant, $user, $warehouse, $product, $supplier];
    }

    private function createReceivedPurchase(Tenant $tenant, User $user, Warehouse $warehouse, Product $product, Supplier $supplier, float $qty = 5): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'document_number' => 'COMPRA-RET-001',
            'issued_at' => now(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'total_base_amount' => $qty * 10,
            'total_local_amount' => $qty * 10,
            'received_base_amount' => $qty * 10,
            'received_local_amount' => $qty * 10,
            'created_by' => $user->id,
        ]);
        PurchaseItem::create([
            'tenant_id' => $tenant->id,
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_cost' => 10,
            'total_cost' => $qty * 10,
            'base_unit_cost' => 10,
            'base_total_cost' => $qty * 10,
            'received_quantity' => $qty,
        ]);
        StockBalance::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => $qty,
        ]);

        return $po;
    }

    private function enqueueEvent(int $tenantId, string $eventType, array $payload, int $aggregateId = 1): void
    {
        $now = now();
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenantId,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => 'purchase_return',
            'aggregate_id' => $aggregateId,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_purchase_return_creation_emits_sync_event(): void
    {
        [$tenant, $user, $warehouse, $product, $supplier] = $this->setupTenant();
        $po = $this->createReceivedPurchase($tenant, $user, $warehouse, $product, $supplier);
        $purchaseItem = $po->items()->first();

        app(PurchaseReturnService::class)->create($user, [
            'purchase_order_id' => $po->id,
            'reason' => 'Defectuoso',
            'items' => [[
                'purchase_item_id' => $purchaseItem->id,
                'quantity' => 2,
            ]],
        ]);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'purchase_return.created',
        ]);
    }

    public function test_applier_applies_purchase_return_decreasing_cloud_stock(): void
    {
        [$tenant, , $warehouse, $product] = $this->setupTenant();

        // La nube ya tiene el PO recibido.
        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'document_number' => 'COMPRA-RET-CLOUD',
            'issued_at' => now(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'total_base_amount' => 50,
            'total_local_amount' => 50,
        ]);
        PurchaseItem::create([
            'tenant_id' => $tenant->id,
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_cost' => 10,
            'total_cost' => 50,
            'base_unit_cost' => 10,
            'base_total_cost' => 50,
            'received_quantity' => 5,
        ]);
        StockBalance::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);

        $this->enqueueEvent($tenant->id, 'purchase_return.created', [
            'return_id' => 55,
            'purchase_order_document' => 'COMPRA-RET-CLOUD',
            'status' => 'processed',
            'reason' => 'Defectuoso',
            'processed_at' => now()->toISOString(),
            'items' => [[
                'sku' => $product->sku,
                'warehouse_code' => $warehouse->code,
                'quantity' => '2.0000',
                'product_serial_units' => [],
                'reason' => 'Dañado',
            ]],
        ], 55);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('purchase_returns', [
            'tenant_id' => $tenant->id,
            'purchase_order_id' => $po->id,
            'status' => 'processed',
        ]);
        // El stock debe decrecer en la nube (salida al proveedor).
        $this->assertEquals(3.0, (float) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity_available'));
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'purchase_return',
            'reference_type' => PurchaseReturn::class,
            'quantity' => '2.0000',
        ]);
    }

    public function test_applier_marks_serialized_units_removed(): void
    {
        [$tenant, , $warehouse, $product] = $this->setupTenant();
        DB::table('products')->where('id', $product->id)->update([
            'tracking_type' => Product::TRACKING_SERIALIZED,
        ]);
        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'document_number' => 'COMPRA-RET-SERIAL',
            'issued_at' => now(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
        ]);
        PurchaseItem::create([
            'tenant_id' => $tenant->id,
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => 10,
            'total_cost' => 10,
            'base_unit_cost' => 10,
            'base_total_cost' => 10,
            'received_quantity' => 1,
        ]);
        $unit = ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => 'imei',
            'serial_number' => '860000000000001',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        StockBalance::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);

        $this->enqueueEvent($tenant->id, 'purchase_return.created', [
            'return_id' => 56,
            'purchase_order_document' => 'COMPRA-RET-SERIAL',
            'status' => 'processed',
            'reason' => 'IMEI defectuoso',
            'processed_at' => now()->toISOString(),
            'items' => [[
                'sku' => $product->sku,
                'warehouse_code' => $warehouse->code,
                'quantity' => '1.0000',
                'product_serial_units' => [
                    ['serial_type' => 'imei', 'serial_number' => '860000000000001'],
                ],
                'reason' => 'Dañado',
            ]],
        ], 56);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertSame('removed', DB::table('product_units')->where('id', $unit->id)->value('status'));
        $this->assertNotNull(DB::table('product_units')->where('id', $unit->id)->value('released_stock_movement_id'));
    }
}
