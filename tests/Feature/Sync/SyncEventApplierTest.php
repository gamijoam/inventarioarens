<?php

namespace Tests\Feature\Sync;

use App\Modules\Sync\Services\SyncEventApplier;
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
}
