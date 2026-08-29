<?php

namespace Tests\Feature\Sync;

use App\Modules\Branches\Models\Branch;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica que sucursales (branches) y almacenes (warehouses) emiten eventos
 * de sync al crearse/actualizarse (antes solo viajaban en la foto inicial), y
 * que el applier los aplica en el nodo destino.
 */
class BranchWarehouseSyncTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): Tenant
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        return $tenant;
    }

    private function enqueueEvent(int $tenantId, string $eventType, array $payload, int $aggregateId = 1): void
    {
        $now = now();
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenantId,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => $eventType,
            'aggregate_id' => $aggregateId,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_creating_branch_emits_branch_created(): void
    {
        $tenant = $this->setupTenant();

        Branch::create(['name' => 'Sucursal Norte', 'code' => 'NORTE']);

        $event = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'branch.created')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('sucursal-norte', json_decode((string) $event->payload, true)['slug']);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'branch.created',
        ]);
    }

    public function test_updating_warehouse_emits_warehouse_updated(): void
    {
        $tenant = $this->setupTenant();
        $branch = Branch::create(['name' => 'Sucursal Central', 'code' => 'CENTRAL']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen 1', 'code' => 'ALM-1']);

        $warehouse->update(['name' => 'Almacen Principal']);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'warehouse.updated',
        ]);
    }

    public function test_applier_applies_branch_and_warehouse_to_cloud(): void
    {
        $tenant = $this->setupTenant();

        $this->enqueueEvent($tenant->id, 'branch.created', [
            'code' => 'SUR',
            'name' => 'Sucursal Sur',
            'slug' => 'sucursal-sur',
            'status' => 'active',
        ], 1);
        $this->enqueueEvent($tenant->id, 'warehouse.created', [
            'code' => 'ALM-SUR',
            'name' => 'Almacen Sur',
            'status' => 'active',
            'branch_code' => 'SUR',
        ], 2);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(2, $summary['applied']);

        $this->assertDatabaseHas('branches', [
            'tenant_id' => $tenant->id,
            'code' => 'SUR',
            'name' => 'Sucursal Sur',
            'slug' => 'sucursal-sur',
        ]);
        $this->assertDatabaseHas('warehouses', [
            'tenant_id' => $tenant->id,
            'code' => 'ALM-SUR',
            'name' => 'Almacen Sur',
        ]);
    }
}
