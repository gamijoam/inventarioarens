<?php

namespace Tests\Feature\Sync;

use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationMovementSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_applies_reservation_and_release_to_available_and_reserved_balances(): void
    {
        $tenant = Tenant::create(['name' => 'Sync Reservations', 'slug' => 'sync-reservations']);
        $now = now();
        $branchId = (int) DB::table('branches')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Principal',
            'code' => 'SYNC',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchId,
            'name' => 'Principal',
            'code' => 'SYNC-01',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Producto reservado',
            'sku' => 'SYNC-RES-01',
            'tracking_type' => 'quantity',
            'base_price' => '10.0000',
            'sale_currency' => 'USD',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('stock_balances')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity_available' => 10,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        $expiresAt = $now->copy()->addMinutes(30)->toISOString();
        $this->inbox($tenant, 'stock-reserved', [
            'source_id' => 100,
            'sku' => 'SYNC-RES-01',
            'warehouse_code' => 'SYNC-01',
            'type' => 'reserved',
            'quantity' => '1.0000',
            'reason' => 'Reserva POS',
            'reservation_expires_at' => $expiresAt,
            'created_at' => $now->toISOString(),
        ]);
        $this->inbox($tenant, 'stock-released', [
            'source_id' => 101,
            'sku' => 'SYNC-RES-01',
            'warehouse_code' => 'SYNC-01',
            'type' => 'released',
            'quantity' => '1.0000',
            'reason' => 'Reserva vencida',
            'reference_type' => 'inventory_reservation_expired',
            'reference_id' => 100,
            'created_at' => $now->copy()->addSecond()->toISOString(),
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);

        $this->assertSame(2, $summary['applied']);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'reference_id' => 100,
            'reservation_expires_at' => $now->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
        ]);
        $balance = DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();
        $this->assertSame(10.0, (float) $balance->quantity_available);
        $this->assertSame(0.0, (float) $balance->quantity_reserved);
    }

    private function inbox(Tenant $tenant, string $eventKey, array $payload): void
    {
        $now = now();
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'stock_movement.created',
            'aggregate_type' => 'stock_movement',
            'aggregate_id' => $payload['source_id'],
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
