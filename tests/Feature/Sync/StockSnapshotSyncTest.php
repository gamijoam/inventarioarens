<?php

namespace Tests\Feature\Sync;

use App\Modules\Branches\Models\Branch;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica que la foto inicial (snapshot) reconstruye el stock_balances al
 * aplicar los stock_movements. Antes solo se guardaban los movimientos y el
 * stock disponible quedaba en 0 en el nodo destino.
 */
class StockSnapshotSyncTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $branch = Branch::create(['name' => 'B', 'code' => 'B1']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'W1', 'code' => 'WH-01']);
        $product = Product::create([
            'name' => 'P',
            'sku' => 'TEST-SKU-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10.0,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$tenant, $warehouse, $product];
    }

    private function enqueueEvent(int $tenantId, string $eventType, array $payload, int $aggregateId = 1): void
    {
        $now = now();
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenantId,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => 'stock_movement',
            'aggregate_id' => $aggregateId,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_snapshot_stock_movements_rebuild_balance(): void
    {
        [$tenant, $warehouse, $product] = $this->setupTenant();

        // Foto inicial: una compra de 10 y una venta de 3 -> stock final 7.
        $this->enqueueEvent($tenant->id, 'stock_movement.created', [
            'source_id' => 1,
            'sku' => $product->sku,
            'warehouse_code' => $warehouse->code,
            'type' => 'purchase',
            'quantity' => '10.0000',
            'unit_cost' => '5.0000',
            'reason' => 'Compra #1',
            'reference_type' => 'purchase',
            'reference_id' => 1,
            'created_at' => now()->toISOString(),
        ], 1);

        $this->enqueueEvent($tenant->id, 'stock_movement.created', [
            'source_id' => 2,
            'sku' => $product->sku,
            'warehouse_code' => $warehouse->code,
            'type' => 'sale',
            'quantity' => '3.0000',
            'unit_cost' => null,
            'reason' => 'Venta #1',
            'reference_type' => 'sale',
            'reference_id' => 1,
            'created_at' => now()->toISOString(),
        ], 2);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(2, $summary['applied']);

        $this->assertEquals(7.0, (float) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity_available'));
    }

    public function test_reprocessing_snapshot_does_not_duplicate_balance(): void
    {
        [$tenant, $warehouse, $product] = $this->setupTenant();

        $payload = [
            'source_id' => 1,
            'sku' => $product->sku,
            'warehouse_code' => $warehouse->code,
            'type' => 'purchase',
            'quantity' => '10.0000',
            'unit_cost' => '5.0000',
            'reason' => 'Compra #1',
            'reference_type' => 'purchase',
            'reference_id' => 1,
            'created_at' => now()->toISOString(),
        ];

        // Primer ciclo: aplica y construye balance = 10.
        $this->enqueueEvent($tenant->id, 'stock_movement.created', $payload, 1);
        app(SyncEventApplier::class)->applyPending($tenant, 10);

        // Re-proceso (p.ej. evento failed -> retry): NO debe duplicar.
        $this->enqueueEvent($tenant->id, 'stock_movement.created', $payload, 1);
        app(SyncEventApplier::class)->applyPending($tenant, 10);

        $this->assertEquals(10.0, (float) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity_available'));
    }
}
