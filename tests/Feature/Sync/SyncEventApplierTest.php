<?php

namespace Tests\Feature\Sync;

use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\AdminPortal\Services\AdminPosSalesService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncEventApplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_product_price_events_without_crossing_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        app(TenantManager::class)->set($tenantA);

        $now = now();
        $productA = $this->product($tenantA, 'SKU-001', 'Producto A', '10.0000');
        $listA = $this->priceList($tenantA, 'DETAL');
        $productB = $this->product($tenantB, 'SKU-001', 'Producto B', '99.0000');
        $listB = $this->priceList($tenantB, 'DETAL');

        DB::table('product_prices')->insert([
            [
                'tenant_id' => $tenantA->id,
                'product_id' => $productA,
                'price_list_id' => $listA,
                'price' => '10.0000',
                'currency' => 'USD',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'tenant_id' => $tenantB->id,
                'product_id' => $productB,
                'price_list_id' => $listB,
                'price' => '99.0000',
                'currency' => 'USD',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenantA->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'product_price.updated',
            'aggregate_type' => 'product_price',
            'aggregate_id' => null,
            'payload_hash' => 'hash',
            'payload' => json_encode([
                'sku' => 'SKU-001',
                'price_list_code' => 'DETAL',
                'price' => '15.5000',
                'currency' => 'USD',
            ]),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenantA);

        $this->assertSame(1, $summary['applied']);
        $this->assertDatabaseHas('product_prices', [
            'tenant_id' => $tenantA->id,
            'product_id' => $productA,
            'price_list_id' => $listA,
            'price' => '15.5000',
        ]);
        $this->assertDatabaseHas('product_prices', [
            'tenant_id' => $tenantB->id,
            'product_id' => $productB,
            'price_list_id' => $listB,
            'price' => '99.0000',
        ]);
    }

    public function test_it_marks_invalid_events_as_failed_with_spanish_message(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Falla', 'slug' => 'empresa-falla']);
        app(TenantManager::class)->set($tenant);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'product_price.updated',
            'aggregate_type' => 'product_price',
            'payload_hash' => 'hash',
            'payload' => json_encode([
                'sku' => 'NO-EXISTE',
                'price_list_code' => 'DETAL',
                'price' => '15.5000',
            ]),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['failed']);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'product_price.updated',
            'status' => 'failed',
        ]);
        $this->assertStringContainsString('No se encontro el producto', DB::table('sync_inbox')->where('tenant_id', $tenant->id)->value('last_error'));
    }

    public function test_it_resolves_product_warranty_policy_by_name_instead_of_cloud_id(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Garantia Sync', 'slug' => 'empresa-garantia-sync']);
        app(TenantManager::class)->set($tenant);

        $now = now();

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'product.created',
            'aggregate_type' => 'product',
            'payload_hash' => 'hash',
            'payload' => json_encode([
                'sku' => 'PROD-GAR-001',
                'name' => 'Producto con garantia nube',
                'tracking_type' => 'quantity',
                'base_price' => '77.7700',
                'sale_currency' => 'USD',
                'warranty_policy_id' => 999,
                'warranty_policy_name' => 'Garantia prueba 7 dias',
                'warranty_policy_duration_days' => 7,
                'warranty_policy_coverage_type' => 'store',
                'warranty_policy_conditions' => 'Cambio por defecto de fabrica.',
                'warranty_policy_is_active' => true,
                'is_active' => true,
            ]),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);
        $policyId = DB::table('warranty_policies')
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Garantia prueba 7 dias')
            ->value('id');

        $this->assertSame(1, $summary['applied']);
        $this->assertNotNull($policyId);
        $this->assertDatabaseHas('products', [
            'tenant_id' => $tenant->id,
            'sku' => 'PROD-GAR-001',
            'name' => 'Producto con garantia nube',
            'warranty_policy_id' => $policyId,
        ]);
        $this->assertDatabaseHas('warranty_policies', [
            'tenant_id' => $tenant->id,
            'name' => 'Garantia prueba 7 dias',
            'duration_days' => 7,
            'coverage_type' => 'store',
        ]);
    }

    public function test_it_applies_initial_catalog_snapshot_events_in_order(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Snapshot Local', 'slug' => 'empresa-snapshot-local']);
        app(TenantManager::class)->set($tenant);
        $now = now();
        $events = [
            ['branch.created', 'branch', ['code' => 'VAL', 'name' => 'Principal Valencia', 'status' => 'active']],
            ['warehouse.created', 'warehouse', ['code' => 'VAL-01', 'name' => 'Almacen Valencia', 'branch_code' => 'VAL', 'status' => 'active']],
            ['exchange_rate_type.created', 'exchange_rate_type', ['code' => 'BCV', 'name' => 'BCV', 'is_default' => true, 'is_active' => true]],
            ['exchange_rate.created', 'exchange_rate', ['exchange_rate_type_code' => 'BCV', 'base_currency' => 'USD', 'quote_currency' => 'VES', 'rate' => '500.000000', 'effective_at' => $now->toISOString(), 'is_active' => true]],
            ['product.created', 'product', ['sku' => 'SAM-A06', 'name' => 'Samsung A06', 'tracking_type' => 'serialized', 'base_price' => '100.0000', 'sale_currency' => 'USD', 'sale_exchange_rate_type_code' => 'BCV', 'is_active' => true]],
            ['stock_movement.created', 'stock_movement', ['source_id' => 90, 'sku' => 'SAM-A06', 'warehouse_code' => 'VAL-01', 'type' => 'purchase', 'quantity' => '1.0000', 'reason' => 'Snapshot inicial']],
            ['product_unit.created', 'product_unit', ['sku' => 'SAM-A06', 'warehouse_code' => 'VAL-01', 'serial_type' => 'imei', 'serial_number' => '860001000001', 'status' => 'available']],
            ['cash_register.created', 'cash_register', ['code' => 'CJ-1', 'name' => 'Caja 1', 'branch_code' => 'VAL', 'status' => 'active']],
        ];

        foreach ($events as $index => [$eventType, $aggregateType, $payload]) {
            DB::table('sync_inbox')->insert([
                'tenant_id' => $tenant->id,
                'event_uuid' => (string) Str::uuid(),
                'event_type' => $eventType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $index + 1,
                'payload_hash' => hash('sha256', json_encode($payload)),
                'payload' => json_encode($payload),
                'status' => 'received',
                'received_at' => $now,
                'created_at' => $now->copy()->addSeconds($index),
                'updated_at' => $now,
            ]);
        }

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 20);

        $this->assertSame(8, $summary['applied']);
        $this->assertDatabaseHas('branches', ['tenant_id' => $tenant->id, 'code' => 'VAL']);
        $this->assertDatabaseHas('warehouses', ['tenant_id' => $tenant->id, 'code' => 'VAL-01']);
        $this->assertDatabaseHas('products', ['tenant_id' => $tenant->id, 'sku' => 'SAM-A06', 'base_price' => '100.0000']);
        $this->assertDatabaseHas('stock_movements', ['tenant_id' => $tenant->id, 'reference_type' => 'sync_snapshot', 'reference_id' => 90]);
        $this->assertDatabaseHas('product_units', ['tenant_id' => $tenant->id, 'serial_number' => '860001000001', 'status' => 'available']);
        $this->assertDatabaseHas('cash_registers', ['tenant_id' => $tenant->id, 'code' => 'CJ-1', 'status' => 'active']);
    }

    public function test_it_materializes_pos_paid_events_for_admin_sales(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Ventas Sync', 'slug' => 'empresa-ventas-sync']);
        app(TenantManager::class)->set($tenant);
        $now = now();
        $branchId = $this->branch($tenant, 'VAL', 'Principal Valencia');
        $warehouseId = $this->warehouse($tenant, $branchId, 'VAL-01', 'Almacen Principal Valencia');
        $productId = $this->product($tenant, 'ADP-BT-VAL', 'Adaptador Bluetooth', '10.0000');
        $priceListId = $this->priceList($tenant, 'DETAL');
        DB::table('product_prices')->insert([
            'tenant_id' => $tenant->id,
            'product_id' => $productId,
            'price_list_id' => $priceListId,
            'price' => '10.0000',
            'currency' => 'USD',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('exchange_rate_types')->insert([
            'tenant_id' => $tenant->id,
            'name' => 'Paralelo',
            'code' => 'PARALELO',
            'is_default' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('payment_methods')->insert([
            'tenant_id' => $tenant->id,
            'name' => 'Pago movil Bs',
            'code' => 'PAGO-MOVIL-BS',
            'method' => 'mobile_payment',
            'currency_mode' => 'VES',
            'requires_reference' => true,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $nodeId = DB::table('sync_nodes')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'LOCAL-VAL-01',
            'name' => 'Local Valencia',
            'type' => 'local',
            'status' => 'active',
            'branch_id' => $branchId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'origin_node_id' => $nodeId,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'pos.order.paid',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => 99,
            'payload_hash' => 'hash-pos-paid',
            'payload' => json_encode([
                'sale' => [
                    'id' => 501,
                    'status' => 'confirmed',
                    'total_base_amount' => '10.0000',
                    'total_local_amount' => '10000.0000',
                    'confirmed_at' => $now->toISOString(),
                ],
                'order' => [
                    'id' => 99,
                    'status' => 'paid',
                    'customer_name' => 'Consumidor final',
                    'branch_name' => 'Principal Valencia',
                    'cash_register_name' => 'Caja Mostrador VAL',
                    'cashier_name' => 'Gerente Valencia',
                    'total_base_amount' => '10.0000',
                    'total_local_amount' => '10000.0000',
                    'paid_base_amount' => '10.0000',
                    'paid_local_amount' => '10000.0000',
                    'opened_at' => $now->toISOString(),
                    'paid_at' => $now->toISOString(),
                    'closed_at' => $now->toISOString(),
                ],
                'items' => [[
                    'id' => 7001,
                    'product_sku' => 'ADP-BT-VAL',
                    'warehouse_code' => 'VAL-01',
                    'price_list_code' => 'DETAL',
                    'price_list_name' => 'Detal',
                    'quantity' => '1.0000',
                    'sale_currency' => 'USD',
                    'unit_price' => '10.0000',
                    'total_amount' => '10.0000',
                    'base_unit_price' => '10.0000',
                    'base_total_amount' => '10.0000',
                    'exchange_rate_type_code' => 'PARALELO',
                    'exchange_rate' => '1000.000000',
                ]],
                'payments' => [[
                    'id' => 9001,
                    'payment_method_code' => 'PAGO-MOVIL-BS',
                    'method' => 'mobile_payment',
                    'currency' => 'VES',
                    'amount' => '10000.0000',
                    'amount_base' => '10.0000',
                    'amount_local' => '10000.0000',
                    'exchange_rate_type_code' => 'PARALELO',
                    'exchange_rate' => '1000.000000',
                    'status' => 'captured',
                    'reference' => '123456',
                ]],
            ]),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['applied']);
        $this->assertDatabaseHas('sales', [
            'tenant_id' => $tenant->id,
            'sync_source_node_code' => 'LOCAL-VAL-01',
            'sync_source_id' => 501,
            'status' => 'confirmed',
            'total_base_amount' => '10.0000',
        ]);
        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenant->id,
            'sync_source_node_code' => 'LOCAL-VAL-01',
            'sync_source_id' => 99,
            'status' => 'paid',
            'sync_cash_register_name' => 'Caja Mostrador VAL',
            'paid_base_amount' => '10.0000',
        ]);
        $this->assertDatabaseHas('pos_payments', [
            'tenant_id' => $tenant->id,
            'sync_source_node_code' => 'LOCAL-VAL-01',
            'sync_source_id' => 9001,
            'currency' => 'VES',
            'amount_base' => '10.0000',
            'exchange_rate_type_code' => 'PARALELO',
        ]);

        $report = app(AdminPosSalesService::class)->index([
            'date_from' => $now->toDateString(),
            'date_to' => $now->toDateString(),
            'status' => 'all',
        ]);

        $this->assertSame(1, $report['summary']['orders_count']);
        $this->assertSame(10.0, $report['summary']['total_base_amount']);
        $this->assertSame('Caja Mostrador VAL', $report['data'][0]['cash_register_name']);
    }

    private function product(Tenant $tenant, string $sku, string $name, string $price): int
    {
        return (int) DB::table('products')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'sku' => $sku,
            'tracking_type' => 'quantity',
            'base_price' => $price,
            'sale_currency' => 'USD',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function priceList(Tenant $tenant, string $code): int
    {
        return (int) DB::table('price_lists')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => $code,
            'code' => $code,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function branch(Tenant $tenant, string $code, string $name): int
    {
        return (int) DB::table('branches')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'code' => $code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function warehouse(Tenant $tenant, int $branchId, string $code, string $name): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchId,
            'name' => $name,
            'code' => $code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
