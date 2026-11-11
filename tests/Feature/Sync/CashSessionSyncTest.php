<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica que las sesiones de caja (cash.session.opened/closed) se emiten con
 * identidad natural y que el applier las aplica en el nodo destino (antes
 * caian en ignored).
 */
class CashSessionSyncTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $cashier = User::factory()->create(['name' => 'Cajero', 'email' => 'cajero.caja@test.test']);
        $cashier->tenants()->attach($tenant, ['status' => 'active']);
        $branch = Branch::create(['name' => 'Sucursal', 'code' => 'SUC-1']);

        return [$tenant, $cashier, $branch];
    }

    private function enqueueEvent(int $tenantId, string $eventType, array $payload, int $aggregateId = 1): void
    {
        $now = now();
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenantId,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => 'cash_register_session',
            'aggregate_id' => $aggregateId,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_creating_cash_register_emits_sync_event(): void
    {
        [$tenant, , $branch] = $this->setupTenant();

        CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 1', 'code' => 'CAJA-1']);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'cash_register.created',
        ]);
    }

    public function test_applier_applies_cash_session_opened_to_cloud(): void
    {
        [$tenant, $cashier, $branch] = $this->setupTenant();
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 1', 'code' => 'CAJA-1']);

        $this->enqueueEvent($tenant->id, 'cash.session.opened', [
            'session_id' => 88,
            'branch_id' => $branch->id,
            'branch_code' => 'SUC-1',
            'cash_register_id' => $register->id,
            'cash_register_code' => 'CAJA-1',
            'cashier_id' => $cashier->id,
            'cashier_email' => 'cajero.caja@test.test',
            'opened_by' => $cashier->id,
            'opened_by_email' => 'cajero.caja@test.test',
            'status' => 'open',
            'opening_base_amount' => '50.0000',
            'opening_local_amount' => '50.0000',
            'expected_base_amount' => '50.0000',
            'expected_local_amount' => '50.0000',
            'counting_mode' => 'standard',
            'review_status' => 'pending',
            'opened_at' => now()->toJSON(),
            'closed_at' => null,
        ], 88);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('cash_register_sessions', [
            'tenant_id' => $tenant->id,
            'sync_source_id' => 88,
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'cashier_id' => $cashier->id,
            'status' => 'open',
            'opening_base_amount' => 50.0,
        ]);
    }

    public function test_applier_applies_cash_session_closed_to_cloud(): void
    {
        [$tenant, $cashier, $branch] = $this->setupTenant();

        $this->enqueueEvent($tenant->id, 'cash.session.closed', [
            'session_id' => 99,
            'branch_id' => $branch->id,
            'branch_code' => 'SUC-1',
            'cash_register_id' => null,
            'cashier_id' => $cashier->id,
            'cashier_email' => 'cajero.caja@test.test',
            'opened_by' => $cashier->id,
            'opened_by_email' => 'cajero.caja@test.test',
            'closed_by' => $cashier->id,
            'closed_by_email' => 'cajero.caja@test.test',
            'status' => 'closed',
            'opening_base_amount' => '50.0000',
            'opening_local_amount' => '50.0000',
            'expected_base_amount' => '50.0000',
            'expected_local_amount' => '50.0000',
            'counted_base_amount' => '52.0000',
            'counted_local_amount' => '52.0000',
            'difference_base_amount' => '2.0000',
            'difference_local_amount' => '2.0000',
            'counting_mode' => 'standard',
            'review_status' => 'pending',
            'opened_at' => now()->toJSON(),
            'closed_at' => now()->toJSON(),
        ], 99);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('cash_register_sessions', [
            'tenant_id' => $tenant->id,
            'sync_source_id' => 99,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'status' => 'closed',
            'counted_base_amount' => 52.0,
            'difference_base_amount' => 2.0,
        ]);
    }

    public function test_applier_replicates_cash_movement_into_replicated_session_once(): void
    {
        [$tenant, $cashier, $branch] = $this->setupTenant();
        $sessionPayload = [
            'session_id' => 77,
            'source_node_code' => 'POS-LOCAL-1',
            'branch_code' => 'SUC-1',
            'cashier_email' => 'cajero.caja@test.test',
            'opened_by_email' => 'cajero.caja@test.test',
            'status' => 'open',
            'opening_base_amount' => '0.0000',
            'opening_local_amount' => '0.0000',
            'expected_base_amount' => '0.0000',
            'expected_local_amount' => '0.0000',
            'opened_at' => now()->toJSON(),
        ];
        $movementPayload = [
            'movement_id' => 501,
            'session_id' => 77,
            'source_node_code' => 'POS-LOCAL-1',
            'type' => 'pos_payment',
            'method' => 'cash',
            'currency' => 'USD',
            'amount' => '25.0000',
            'amount_base' => '25.0000',
            'amount_local' => null,
            'source_type' => 'App\\Modules\\POS\\Models\\PosPayment',
            'reference' => null,
            'notes' => 'Pago POS #9',
            'created_by_email' => 'cajero.caja@test.test',
            'created_at' => now()->subMinutes(5)->toJSON(),
        ];

        // El movimiento llega antes que la sesion: falla y queda para reproceso.
        $this->enqueueEvent($tenant->id, 'cash.movement.recorded', $movementPayload, 501);
        $first = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $first['failed']);
        $this->assertDatabaseCount('cash_register_movements', 0);

        $this->enqueueEvent($tenant->id, 'cash.session.opened', $sessionPayload, 77);
        app(SyncEventApplier::class)->applyPending($tenant, 10);
        app(SyncEventApplier::class)->applyPending($tenant, 10);

        $sessionId = DB::table('cash_register_sessions')->where('sync_source_id', 77)->value('id');
        $this->assertDatabaseHas('cash_register_movements', [
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $sessionId,
            'sync_source_node_code' => 'POS-LOCAL-1',
            'sync_source_id' => 501,
            'type' => 'pos_payment',
            'method' => 'cash',
            'amount' => 25.0,
            'created_by' => $cashier->id,
        ]);
        $movement = DB::table('cash_register_movements')->where('sync_source_id', 501)->first();
        $this->assertNotNull($movement->created_at);
        $this->assertNotNull($movement->updated_at);

        // Reenvio del mismo movimiento (otro event_uuid): no se duplica.
        $this->enqueueEvent($tenant->id, 'cash.movement.recorded', $movementPayload, 501);
        app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, DB::table('cash_register_movements')->where('sync_source_id', 501)->count());
    }
}
