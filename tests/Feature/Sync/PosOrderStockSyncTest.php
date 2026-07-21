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

class PosOrderStockSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_pos_order_sync_decrements_stock_balance_in_cloud(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa POS Sync', 'slug' => 'empresa-pos-sync-stock']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'Sucursal Sync', 'code' => 'BR-SYNC']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen Sync', 'code' => 'WH-SYNC']);
        $productId = Product::query()->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Producto Sync',
            'sku' => 'SKU-POS-SYNC',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        DB::table('stock_balances')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $productId,
            'quantity_available' => 5,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        $payload = json_encode([
            'order_id' => 1,
            'sale_id' => 10,
            'sale_status' => 'confirmed',
            'status' => 'paid',
            'items' => [[
                'id' => 100,
                'product_sku' => 'SKU-POS-SYNC',
                'warehouse_code' => 'WH-SYNC',
                'price_list_code' => null,
                'quantity' => '2.0000',
                'sale_currency' => 'USD',
                'unit_price' => '10.0000',
                'total_amount' => '20.0000',
                'base_unit_price' => '10.0000',
                'base_total_amount' => '20.0000',
                'exchange_rate_type_code' => null,
                'exchange_rate' => null,
                'product_unit_ids' => [],
                'product_serial_units' => [],
            ]],
            'payments' => [],
        ]);

        $uuid = (string) Str::uuid();
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $uuid,
            'event_type' => 'pos.order.paid',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => 1,
            'payload_hash' => hash('sha256', $payload),
            'payload' => $payload,
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame('3.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $productId)
            ->value('quantity_available'));
    }

    public function test_paid_pos_order_sync_marks_serialized_units_as_sold(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa POS Sync IMEI', 'slug' => 'empresa-pos-sync-imei']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'Sucursal IMEI', 'code' => 'BR-IMEI']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen IMEI', 'code' => 'WH-IMEI']);
        $productId = Product::query()->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Celular IMEI',
            'sku' => 'SKU-IMEI',
            'tracking_type' => Product::TRACKING_SERIALIZED,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        DB::table('stock_balances')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $productId,
            'quantity_available' => 2,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        ProductUnit::create([
            'tenant_id' => $tenant->id,
            'product_id' => $productId,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-CLOUD-001',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        ProductUnit::create([
            'tenant_id' => $tenant->id,
            'product_id' => $productId,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-CLOUD-002',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);

        $payload = json_encode([
            'order_id' => 2,
            'sale_id' => 20,
            'sale_status' => 'confirmed',
            'status' => 'paid',
            'items' => [[
                'id' => 200,
                'product_sku' => 'SKU-IMEI',
                'warehouse_code' => 'WH-IMEI',
                'price_list_code' => null,
                'quantity' => '2.0000',
                'sale_currency' => 'USD',
                'unit_price' => '500.0000',
                'total_amount' => '1000.0000',
                'base_unit_price' => '500.0000',
                'base_total_amount' => '1000.0000',
                'exchange_rate_type_code' => null,
                'exchange_rate' => null,
                'product_unit_ids' => [],
                'product_serial_units' => [
                    ['serial_type' => 'imei', 'serial_number' => 'IMEI-CLOUD-001'],
                    ['serial_type' => 'imei', 'serial_number' => 'IMEI-CLOUD-002'],
                ],
            ]],
            'payments' => [],
        ]);

        $uuid = (string) Str::uuid();
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $uuid,
            'event_type' => 'pos.order.paid',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => 2,
            'payload_hash' => hash('sha256', $payload),
            'payload' => $payload,
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame('0.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $productId)
            ->value('quantity_available'));

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'product_id' => $productId,
            'serial_number' => 'IMEI-CLOUD-001',
            'status' => ProductUnit::STATUS_SOLD,
            'warehouse_id' => $warehouse->id,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'product_id' => $productId,
            'serial_number' => 'IMEI-CLOUD-002',
            'status' => ProductUnit::STATUS_SOLD,
            'warehouse_id' => $warehouse->id,
        ]);
    }

    public function test_pending_pos_order_sync_does_not_move_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa POS Pendiente', 'slug' => 'empresa-pos-pendiente']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'Sucursal Pend', 'code' => 'BR-PEND']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen Pend', 'code' => 'WH-PEND']);
        $productId = Product::query()->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Producto Pend',
            'sku' => 'SKU-PEND',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        DB::table('stock_balances')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $productId,
            'quantity_available' => 5,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        $payload = json_encode([
            'order_id' => 3,
            'sale_id' => 30,
            'sale_status' => 'draft',
            'status' => 'open',
            'items' => [[
                'id' => 300,
                'product_sku' => 'SKU-PEND',
                'warehouse_code' => 'WH-PEND',
                'price_list_code' => null,
                'quantity' => '2.0000',
                'sale_currency' => 'USD',
                'unit_price' => '10.0000',
                'total_amount' => '20.0000',
                'base_unit_price' => '10.0000',
                'base_total_amount' => '20.0000',
                'exchange_rate_type_code' => null,
                'exchange_rate' => null,
                'product_unit_ids' => [],
                'product_serial_units' => [],
            ]],
            'payments' => [],
        ]);

        $uuid = (string) Str::uuid();
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $uuid,
            'event_type' => 'pos.order.pending',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => 3,
            'payload_hash' => hash('sha256', $payload),
            'payload' => $payload,
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame('5.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $productId)
            ->value('quantity_available'));
    }
}
