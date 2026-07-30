<?php

namespace Tests\Feature\Sync;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Products\Models\Product;
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
