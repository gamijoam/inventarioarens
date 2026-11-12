<?php

namespace Tests\Feature\Sync;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesReturnSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_processed_serialized_return_restores_stock_and_imei_once(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa devolucion sync', 'slug' => 'empresa-devolucion-sync']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'Sucursal devolucion', 'code' => 'BR-RETURN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen devolucion', 'code' => 'WH-RETURN']);
        $productId = Product::query()->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Telefono serializado',
            'sku' => 'SKU-RETURN-IMEI',
            'tracking_type' => Product::TRACKING_SERIALIZED,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        DB::table('stock_balances')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $productId,
            'quantity_available' => 1,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);
        ProductUnit::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-RETURN-001',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);

        $this->insertInboxEvent($tenant, 'pos.order.paid', [
            'source_node_code' => 'LOCAL-A',
            'order_id' => 100,
            'sale_id' => 200,
            'sale_status' => 'confirmed',
            'status' => 'paid',
            'items' => [[
                'id' => 300,
                'product_sku' => 'SKU-RETURN-IMEI',
                'warehouse_code' => 'WH-RETURN',
                'quantity' => '1.0000',
                'sale_currency' => 'USD',
                'unit_price' => '500.0000',
                'total_amount' => '500.0000',
                'base_unit_price' => '500.0000',
                'base_total_amount' => '500.0000',
                'product_serial_units' => [[
                    'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                    'serial_number' => 'IMEI-RETURN-001',
                ]],
            ]],
            'payments' => [],
        ], 100);

        app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(0.0, (float) DB::table('stock_balances')->where('tenant_id', $tenant->id)->value('quantity_available'));
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'serial_number' => 'IMEI-RETURN-001',
            'status' => ProductUnit::STATUS_SOLD,
        ]);

        $returnPayload = [
            'source_node_code' => 'CLOUD-01',
            'return' => [
                'id' => 400,
                'status' => 'processed',
                'reason' => 'Equipo devuelto en buen estado',
                'processed_at' => now()->toISOString(),
            ],
            'sale' => [
                'id' => 200,
                'source_node_code' => 'LOCAL-A',
            ],
            'items' => [[
                'id' => 500,
                'sale_item_id' => 300,
                'sale_item_source_node_code' => 'LOCAL-A',
                'product_sku' => 'SKU-RETURN-IMEI',
                'warehouse_code' => 'WH-RETURN',
                'quantity' => '1.0000',
                'condition' => 'sellable',
                'product_serial_units' => [[
                    'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                    'serial_number' => 'IMEI-RETURN-001',
                ]],
            ]],
        ];

        $this->insertInboxEvent($tenant, 'sales_return.updated', $returnPayload, 400);
        $this->insertInboxEvent($tenant, 'sales_return.updated', $returnPayload, 400);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(['applied' => 2, 'failed' => 0, 'ignored' => 0], $summary);
        $this->assertSame(1.0, (float) DB::table('stock_balances')->where('tenant_id', $tenant->id)->value('quantity_available'));
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'serial_number' => 'IMEI-RETURN-001',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $this->assertSame(1, DB::table('sales_returns')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, DB::table('sales_return_items')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, DB::table('stock_movements')
            ->where('tenant_id', $tenant->id)
            ->where('type', 'sale_return')
            ->count());
    }

    public function test_variant_pos_sale_and_return_move_the_variant_balance_in_the_cloud(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa variantes sync', 'slug' => 'empresa-variantes-sync']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'Sucursal variantes', 'code' => 'BR-VAR']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen variantes', 'code' => 'WH-VAR']);
        $product = Product::create(['name' => 'Franela', 'sku' => 'SKU-VAR', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 20, 'sale_currency' => Product::CURRENCY_USD]);
        $red = ProductVariant::create(['product_id' => $product->id, 'color' => 'Rojo', 'sku_variant' => 'SKU-VAR-R']);
        $blue = ProductVariant::create(['product_id' => $product->id, 'color' => 'Azul', 'sku_variant' => 'SKU-VAR-B']);
        foreach ([[$blue, 5], [$red, 5]] as [$variant, $quantity]) {
            DB::table('stock_balances')->insert([
                'tenant_id' => $tenant->id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity_available' => $quantity,
                'quantity_reserved' => 0,
                'quantity_damaged' => 0,
            ]);
        }

        $this->insertInboxEvent($tenant, 'pos.order.paid', [
            'source_node_code' => 'LOCAL-A',
            'order_id' => 110,
            'sale_id' => 210,
            'sale_status' => 'confirmed',
            'status' => 'paid',
            'items' => [[
                'id' => 310,
                'product_sku' => 'SKU-VAR',
                'product_variant_sku' => 'SKU-VAR-R',
                'product_variant_color' => 'Rojo',
                'warehouse_code' => 'WH-VAR',
                'quantity' => '2.0000',
                'sale_currency' => 'USD',
                'unit_price' => '20.0000',
                'total_amount' => '40.0000',
                'base_unit_price' => '20.0000',
                'base_total_amount' => '40.0000',
            ]],
            'payments' => [],
        ], 110);
        app(SyncEventApplier::class)->applyPending($tenant);

        $balance = fn ($variant): float => (float) DB::table('stock_balances')->where('tenant_id', $tenant->id)->where('product_variant_id', $variant->id)->value('quantity_available');
        $this->assertSame(3.0, $balance($red), 'La venta descuenta la variante vendida.');
        $this->assertSame(5.0, $balance($blue), 'Las demas variantes no se tocan.');
        $this->assertSame($red->id, (int) DB::table('sale_items')->where('tenant_id', $tenant->id)->value('product_variant_id'));

        $this->insertInboxEvent($tenant, 'sales_return.updated', [
            'source_node_code' => 'CLOUD-01',
            'return' => ['id' => 410, 'status' => 'processed', 'reason' => 'Talla equivocada', 'processed_at' => now()->toISOString()],
            'sale' => ['id' => 210, 'source_node_code' => 'LOCAL-A'],
            'items' => [[
                'id' => 510,
                'sale_item_id' => 310,
                'sale_item_source_node_code' => 'LOCAL-A',
                'product_sku' => 'SKU-VAR',
                'warehouse_code' => 'WH-VAR',
                'quantity' => '1.0000',
                'condition' => 'sellable',
            ]],
        ], 410);
        app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(4.0, $balance($red), 'La devolucion vuelve a la variante vendida.');
        $this->assertSame(5.0, $balance($blue));

        $this->insertInboxEvent($tenant, 'sales_return.updated', [
            'source_node_code' => 'CLOUD-01',
            'return' => ['id' => 411, 'status' => 'processed', 'reason' => 'Llego rota', 'processed_at' => now()->toISOString()],
            'sale' => ['id' => 210, 'source_node_code' => 'LOCAL-A'],
            'items' => [[
                'id' => 511,
                'sale_item_id' => 310,
                'sale_item_source_node_code' => 'LOCAL-A',
                'product_sku' => 'SKU-VAR',
                'warehouse_code' => 'WH-VAR',
                'quantity' => '1.0000',
                'condition' => 'damaged',
            ]],
        ], 411);
        app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1.0, (float) DB::table('stock_balances')->where('tenant_id', $tenant->id)->where('product_variant_id', $red->id)->value('quantity_damaged'), 'La devolucion danada va a la variante.');
        $this->assertSame(0, DB::table('stock_balances')->where('tenant_id', $tenant->id)->whereNull('product_variant_id')->count());
    }

    private function insertInboxEvent(Tenant $tenant, string $eventType, array $payload, int $aggregateId): void
    {
        $encodedPayload = json_encode($payload);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => 'sales_return',
            'aggregate_id' => $aggregateId,
            'payload_hash' => hash('sha256', $encodedPayload),
            'payload' => $encodedPayload,
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
