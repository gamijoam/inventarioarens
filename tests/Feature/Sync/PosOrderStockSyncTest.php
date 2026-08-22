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
use Illuminate\Support\Carbon;
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
            'promotion_applications' => [[
                'slot' => 'invoice',
                'scope' => 'invoice',
                'status' => 'applied',
                'instance_uuid' => null,
                'promotion_code' => 'SYNC-INVOICE-10',
                'promotion_name' => 'Descuento factura sincronizado',
                'benefit_type' => 'percent_discount',
                'payment_currency' => 'ANY',
                'price_usd' => null,
                'discount_percent' => '10.00',
                'discount_amount_usd' => null,
                'conditions_snapshot' => ['allows_combos' => true],
                'base_before_amount' => '22.2222',
                'local_before_amount' => '2222.2200',
                'base_adjustment_amount' => '-2.2222',
                'local_adjustment_amount' => '-222.2200',
                'base_after_amount' => '20.0000',
                'local_after_amount' => '2000.0000',
                'requested_at' => '2026-08-16T12:00:00Z',
                'validated_at' => '2026-08-16T12:01:00Z',
                'rejected_at' => null,
                'items' => [[
                    'sale_item_id' => 100,
                    'quantity' => '2.0000',
                    'base_before_amount' => '22.2222',
                    'local_before_amount' => '2222.2200',
                    'base_adjustment_amount' => '-2.2222',
                    'local_adjustment_amount' => '-222.2200',
                    'base_after_amount' => '20.0000',
                    'local_after_amount' => '2000.0000',
                ]],
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

        $this->assertEquals(3.0, (float) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $productId)
            ->value('quantity_available'));
        $application = DB::table('sale_promotion_applications')
            ->where('tenant_id', $tenant->id)
            ->where('slot', 'invoice')
            ->first();
        $this->assertNotNull($application);
        $this->assertSame('SYNC-INVOICE-10', $application->promotion_code);
        $this->assertSame('applied', $application->status);
        $this->assertNotNull($application->created_at);
        $this->assertNotNull($application->updated_at);
        $applicationItem = DB::table('sale_promotion_application_items')
            ->where('tenant_id', $tenant->id)
            ->where('sale_promotion_application_id', $application->id)
            ->first();
        $this->assertNotNull($applicationItem);
        $this->assertNotNull($applicationItem->created_at);
        $this->assertNotNull($applicationItem->updated_at);
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

        $this->assertEquals(0.0, (float) DB::table('stock_balances')
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

        $this->assertEquals(5.0, (float) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $productId)
            ->value('quantity_available'));
    }

    public function test_credit_sale_and_later_collection_sync_once_after_recovery(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa POS Credito', 'slug' => 'empresa-pos-credito-sync']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'Sucursal Credito', 'code' => 'BR-CRED']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen Credito', 'code' => 'WH-CRED']);
        $productId = Product::query()->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Producto Credito',
            'sku' => 'SKU-CRED-SYNC',
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

        $creditPayload = [
            'source_node_code' => 'LOCAL-01',
            'order_id' => 4,
            'sale_id' => 40,
            'sale_status' => 'confirmed',
            'status' => 'paid',
            'total_base_amount' => '20.0000',
            'total_local_amount' => '0.0000',
            'paid_base_amount' => '10.0000',
            'paid_local_amount' => '0.0000',
            'items' => [[
                'id' => 400,
                'product_sku' => 'SKU-CRED-SYNC',
                'warehouse_code' => 'WH-CRED',
                'quantity' => '2.0000',
                'sale_currency' => 'USD',
                'unit_price' => '10.0000',
                'total_amount' => '20.0000',
                'base_unit_price' => '10.0000',
                'base_total_amount' => '20.0000',
                'product_unit_ids' => [],
                'product_serial_units' => [],
            ]],
            'payments' => [],
            'receivable' => [
                'status' => 'partial',
                'document_number' => 'VENTA-40',
                'currency' => 'USD',
                'original_base_amount' => '20.0000',
                'original_local_amount' => '0.0000',
                'returned_base_amount' => '0.0000',
                'returned_local_amount' => '0.0000',
                'collected_base_amount' => '10.0000',
                'collected_local_amount' => '0.0000',
                'adjusted_base_amount' => '0.0000',
                'adjusted_local_amount' => '0.0000',
                'balance_base_amount' => '10.0000',
                'balance_local_amount' => '0.0000',
                'payments' => [[
                    'id' => 401,
                    'payment_currency' => 'USD',
                    'amount' => '10.0000',
                    'amount_base' => '10.0000',
                    'amount_local' => '0.0000',
                    'method' => 'pos_cash',
                    'reference' => 'POS-PAYMENT-401',
                ]],
            ],
        ];

        $this->insertInboxEvent($tenant, 'pos.order.paid', $creditPayload, 4);
        $this->insertInboxEvent($tenant, 'pos.order.paid', $creditPayload, 4);
        app(SyncEventApplier::class)->applyPending($tenant);

        $saleId = (int) DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->where('sync_source_node_code', 'LOCAL-01')
            ->where('sync_source_id', 40)
            ->value('id');
        $accountId = (int) DB::table('accounts_receivables')->where('tenant_id', $tenant->id)->where('sale_id', $saleId)->value('id');

        $this->assertEquals(3.0, (float) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $productId)
            ->value('quantity_available'));
        $this->assertEquals(1, DB::table('accounts_receivables')->where('tenant_id', $tenant->id)->count());
        $this->assertEquals(1, DB::table('accounts_receivable_payments')->where('accounts_receivable_id', $accountId)->count());
        $this->assertEquals(10.0, (float) DB::table('accounts_receivables')->where('id', $accountId)->value('balance_base_amount'));

        $collectionPayload = [
            'source_node_code' => 'LOCAL-01',
            'sale_id' => 40,
            'receivable' => array_merge($creditPayload['receivable'], [
                'status' => 'paid',
                'collected_base_amount' => '20.0000',
                'balance_base_amount' => '0.0000',
            ]),
            'payment' => [
                'id' => 402,
                'payment_currency' => 'USD',
                'amount' => '10.0000',
                'amount_base' => '10.0000',
                'amount_local' => '0.0000',
                'method' => 'transfer',
                'reference' => 'COBRO-402',
            ],
        ];

        $this->insertInboxEvent($tenant, 'accounts_receivable.payment_registered', $collectionPayload, 40);
        $this->insertInboxEvent($tenant, 'accounts_receivable.payment_registered', $collectionPayload, 40);
        app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertEquals(0.0, (float) DB::table('accounts_receivables')->where('id', $accountId)->value('balance_base_amount'));
        $this->assertEquals(2, DB::table('accounts_receivable_payments')->where('accounts_receivable_id', $accountId)->count());
        $this->assertEquals(3.0, (float) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $productId)
            ->value('quantity_available'));
    }

    public function test_conflicting_remote_sales_do_not_clip_the_last_unit_or_confirm_the_second_sale(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Ultima Unidad', 'slug' => 'empresa-ultima-unidad']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'Sucursal Ultima Unidad', 'code' => 'BR-LAST']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen Ultima Unidad', 'code' => 'WH-LAST']);
        $productId = Product::query()->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Producto Ultima Unidad',
            'sku' => 'SKU-LAST-UNIT',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
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

        $firstUuid = $this->insertInboxEvent($tenant, 'pos.order.paid', $this->paidOrderPayload(
            sourceNodeCode: 'LOCAL-RACE-A',
            orderId: 501,
            saleId: 501,
            productSku: 'SKU-LAST-UNIT',
            warehouseCode: 'WH-LAST',
        ), 501);
        $secondUuid = $this->insertInboxEvent($tenant, 'pos.order.paid', $this->paidOrderPayload(
            sourceNodeCode: 'LOCAL-RACE-B',
            orderId: 502,
            saleId: 502,
            productSku: 'SKU-LAST-UNIT',
            warehouseCode: 'WH-LAST',
        ), 502);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(['applied' => 1, 'failed' => 1, 'ignored' => 0], $summary);
        $this->assertEquals(0.0, (float) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $productId)
            ->value('quantity_available'));
        $this->assertSame(1, DB::table('sales')->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('sync_inbox', ['event_uuid' => $firstUuid, 'status' => 'applied']);
        $this->assertDatabaseHas('sync_inbox', ['event_uuid' => $secondUuid, 'status' => 'failed']);
        $this->assertStringContainsString('stock insuficiente', (string) DB::table('sync_inbox')
            ->where('event_uuid', $secondUuid)
            ->value('last_error'));
    }

    public function test_older_pending_event_cannot_revert_a_paid_pos_order(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Orden Sync', 'slug' => 'empresa-orden-sync']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'Sucursal Orden', 'code' => 'BR-ORDER']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen Orden', 'code' => 'WH-ORDER']);
        $productId = Product::query()->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Producto Orden Sync',
            'sku' => 'SKU-ORDER-SYNC',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
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

        $paidAt = now();
        $paidUuid = $this->insertInboxEvent(
            $tenant,
            'pos.order.paid',
            $this->paidOrderPayload(
                sourceNodeCode: 'LOCAL-ORDER-01',
                orderId: 701,
                saleId: 701,
                productSku: 'SKU-ORDER-SYNC',
                warehouseCode: 'WH-ORDER',
                occurredAt: $paidAt->toISOString(),
            ),
            701,
            $paidAt,
        );
        $pendingUuid = $this->insertInboxEvent(
            $tenant,
            'pos.order.pending',
            $this->paidOrderPayload(
                sourceNodeCode: 'LOCAL-ORDER-01',
                orderId: 701,
                saleId: 701,
                productSku: 'SKU-ORDER-SYNC',
                warehouseCode: 'WH-ORDER',
                occurredAt: $paidAt->copy()->subMinute()->toISOString(),
                status: 'open',
                saleStatus: 'draft',
            ),
            701,
            $paidAt->copy()->subMinute(),
        );

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(['applied' => 1, 'failed' => 0, 'ignored' => 1], $summary);
        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenant->id,
            'sync_source_node_code' => 'LOCAL-ORDER-01',
            'sync_source_id' => 701,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('sales', [
            'tenant_id' => $tenant->id,
            'sync_source_node_code' => 'LOCAL-ORDER-01',
            'sync_source_id' => 701,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('sync_inbox', ['event_uuid' => $paidUuid, 'status' => 'applied']);
        $this->assertDatabaseHas('sync_inbox', ['event_uuid' => $pendingUuid, 'status' => 'ignored']);
    }

    public function test_conflicting_remote_imei_sale_keeps_the_second_event_for_manual_resolution(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa IMEI Unico', 'slug' => 'empresa-imei-unico']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'Sucursal IMEI Unico', 'code' => 'BR-IMEI-LAST']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen IMEI Unico', 'code' => 'WH-IMEI-LAST']);
        $productId = Product::query()->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Equipo IMEI Unico',
            'sku' => 'SKU-IMEI-LAST',
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
            'serial_number' => 'IMEI-RACE-001',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);

        $firstUuid = $this->insertInboxEvent($tenant, 'pos.order.paid', $this->paidOrderPayload(
            sourceNodeCode: 'LOCAL-IMEI-A',
            orderId: 601,
            saleId: 601,
            productSku: 'SKU-IMEI-LAST',
            warehouseCode: 'WH-IMEI-LAST',
            serialNumber: 'IMEI-RACE-001',
        ), 601);
        $secondUuid = $this->insertInboxEvent($tenant, 'pos.order.paid', $this->paidOrderPayload(
            sourceNodeCode: 'LOCAL-IMEI-B',
            orderId: 602,
            saleId: 602,
            productSku: 'SKU-IMEI-LAST',
            warehouseCode: 'WH-IMEI-LAST',
            serialNumber: 'IMEI-RACE-001',
        ), 602);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(['applied' => 1, 'failed' => 1, 'ignored' => 0], $summary);
        $this->assertEquals(1.0, (float) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $productId)
            ->value('quantity_available'));
        $this->assertSame(1, DB::table('sales')->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'serial_number' => 'IMEI-RACE-001',
            'status' => ProductUnit::STATUS_SOLD,
        ]);
        $this->assertDatabaseHas('sync_inbox', ['event_uuid' => $firstUuid, 'status' => 'applied']);
        $this->assertDatabaseHas('sync_inbox', ['event_uuid' => $secondUuid, 'status' => 'failed']);
        $this->assertStringContainsString('ya no esta disponible', (string) DB::table('sync_inbox')
            ->where('event_uuid', $secondUuid)
            ->value('last_error'));
    }

    private function paidOrderPayload(
        string $sourceNodeCode,
        int $orderId,
        int $saleId,
        string $productSku,
        string $warehouseCode,
        ?string $serialNumber = null,
        ?string $occurredAt = null,
        string $status = 'paid',
        string $saleStatus = 'confirmed',
    ): array {
        return [
            'source_node_code' => $sourceNodeCode,
            'order_id' => $orderId,
            'sale_id' => $saleId,
            'sale_status' => $saleStatus,
            'status' => $status,
            'occurred_at' => $occurredAt,
            'total_base_amount' => '10.0000',
            'total_local_amount' => '0.0000',
            'paid_base_amount' => '10.0000',
            'paid_local_amount' => '0.0000',
            'items' => [[
                'id' => $orderId,
                'product_sku' => $productSku,
                'warehouse_code' => $warehouseCode,
                'price_list_code' => null,
                'quantity' => '1.0000',
                'sale_currency' => 'USD',
                'unit_price' => '10.0000',
                'total_amount' => '10.0000',
                'base_unit_price' => '10.0000',
                'base_total_amount' => '10.0000',
                'exchange_rate_type_code' => null,
                'exchange_rate' => null,
                'product_unit_ids' => [],
                'product_serial_units' => $serialNumber === null
                    ? []
                    : [['serial_type' => ProductUnit::SERIAL_TYPE_IMEI, 'serial_number' => $serialNumber]],
            ]],
            'payments' => [],
        ];
    }

    private function insertInboxEvent(
        Tenant $tenant,
        string $eventType,
        array $payload,
        int $aggregateId,
        ?Carbon $occurredAt = null,
    ): string {
        $encodedPayload = json_encode($payload);
        $eventUuid = (string) Str::uuid();

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'event_type' => $eventType,
            'aggregate_type' => 'pos_order',
            'aggregate_id' => $aggregateId,
            'payload_hash' => hash('sha256', $encodedPayload),
            'payload' => $encodedPayload,
            'status' => 'received',
            'occurred_at' => $occurredAt ?? now(),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $eventUuid;
    }
}
