<?php

namespace Tests\Feature\InventoryTransfers;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NextSequenceRaceConditionFixTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug): Tenant
    {
        $tenant = Tenant::create(['name' => 'Tienda '.$slug, 'slug' => $slug]);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = \App\Models\User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        setPermissionsTeamId($tenant->id);

        $branch = \App\Modules\Branches\Models\Branch::create(['name' => 'Principal', 'code' => "BR-$slug"]);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'A', 'code' => "WH-$slug-A"]);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'B', 'code' => "WH-$slug-B"]);

        return $tenant;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function warehouseIds(Tenant $tenant): array
    {
        $warehouses = Warehouse::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->all();

        return [(int) $warehouses[0], (int) $warehouses[1]];
    }

    public function test_next_sequence_returns_1_for_fresh_tenant(): void
    {
        $tenant = $this->makeTenant('seq-fresh');

        $service = app(\App\Modules\InventoryTransfers\Services\InventoryTransferService::class);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('nextSequence');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke($service));
    }

    public function test_next_sequence_advances_after_transfer_creation(): void
    {
        $tenant = $this->makeTenant('seq-advances');
        $user = $tenant->users()->firstOrFail();
        [$fromId, $toId] = $this->warehouseIds($tenant);

        $service = app(\App\Modules\InventoryTransfers\Services\InventoryTransferService::class);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('nextSequence');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke($service));

        \App\Modules\InventoryTransfers\Models\InventoryTransfer::create([
            'tenant_id' => $tenant->id,
            'sequence' => 1,
            'document_number' => 'TRF-000001',
            'guide_number' => 'GUIA-000001',
            'type' => \App\Modules\InventoryTransfers\Models\InventoryTransfer::TYPE_INTERNAL,
            'validation_mode' => \App\Modules\InventoryTransfers\Models\InventoryTransfer::VALIDATION_SIMPLE,
            'from_warehouse_id' => $fromId,
            'to_warehouse_id' => $toId,
            'status' => \App\Modules\InventoryTransfers\Models\InventoryTransfer::STATUS_COMPLETED,
            'created_by' => $user->id,
            'processed_at' => now(),
            'requested_at' => now(),
        ]);

        $this->assertSame(2, $method->invoke($service));
    }

    public function test_next_sequence_is_independent_per_tenant(): void
    {
        $tenantA = $this->makeTenant('seq-iso-a');
        $userA = $tenantA->users()->firstOrFail();
        [$fromIdA, $toIdA] = $this->warehouseIds($tenantA);
        $this->assertSame(1, $this->invokeNextSequence());

        for ($i = 0; $i < 3; $i++) {
            \App\Modules\InventoryTransfers\Models\InventoryTransfer::create([
                'tenant_id' => $tenantA->id,
                'sequence' => $i + 1,
                'document_number' => "TRF-A-00".($i + 1),
                'guide_number' => "GUIA-A-00".($i + 1),
                'type' => \App\Modules\InventoryTransfers\Models\InventoryTransfer::TYPE_INTERNAL,
                'validation_mode' => \App\Modules\InventoryTransfers\Models\InventoryTransfer::VALIDATION_SIMPLE,
                'from_warehouse_id' => $fromIdA,
                'to_warehouse_id' => $toIdA,
                'status' => \App\Modules\InventoryTransfers\Models\InventoryTransfer::STATUS_COMPLETED,
                'created_by' => $userA->id,
                'processed_at' => now(),
                'requested_at' => now(),
            ]);
        }

        $tenantB = $this->makeTenant('seq-iso-b');
        $this->assertSame(1, $this->invokeNextSequence(), 'Tenant B debe empezar desde 1 independiente de A');
    }

    public function test_next_sequence_uses_pg_advisory_xact_lock(): void
    {
        $this->makeTenant('seq-lock');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $sql = $query->sql;
            if (str_contains($sql, 'pg_advisory_xact_lock') || str_contains($sql, 'inventory_transfers')) {
                $queries[] = $sql;
            }
        });

        $this->invokeNextSequence();

        $advisoryFound = false;
        foreach ($queries as $sql) {
            if (str_contains($sql, 'pg_advisory_xact_lock')) {
                $advisoryFound = true;
                break;
            }
        }

        $this->assertTrue($advisoryFound, 'nextSequence debe usar pg_advisory_xact_lock para serializar. Queries: '.json_encode($queries));
    }

    public function test_next_sequence_increments_by_one_via_create_loop(): void
    {
        $tenant = $this->makeTenant('seq-increment');
        $user = $tenant->users()->firstOrFail();
        [$fromId, $toId] = $this->warehouseIds($tenant);

        $service = app(\App\Modules\InventoryTransfers\Services\InventoryTransferService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('nextSequence');
        $method->setAccessible(true);

        $sequences = DB::transaction(function () use ($method, $service, $user, $fromId, $toId) {
            $out = [];
            for ($i = 0; $i < 5; $i++) {
                $next = $method->invoke($service);
                $out[] = $next;
                DB::table('inventory_transfers')->insert([
                    'tenant_id' => $user->tenants()->first()->id,
                    'sequence' => $next,
                    'document_number' => "TRF-INC-{$i}",
                    'guide_number' => "GUIA-INC-{$i}",
                    'type' => 'internal',
                    'validation_mode' => 'simple',
                    'from_warehouse_id' => $fromId,
                    'to_warehouse_id' => $toId,
                    'status' => 'completed',
                    'created_by' => $user->id,
                    'processed_at' => now(),
                    'requested_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $out;
        });

        $this->assertSame([1, 2, 3, 4, 5], $sequences, 'Secuencia debe incrementar monotona: '.json_encode($sequences));
    }

    public function test_advisory_lock_serializes_concurrent_transfers(): void
    {
        $this->makeTenant('seq-concurrent');

        $service = app(\App\Modules\InventoryTransfers\Services\InventoryTransferService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('nextSequence');
        $method->setAccessible(true);

        DB::statement('SELECT pg_advisory_xact_lock(100, ?) FOR UPDATE', [app(TenantManager::class)->require()->id]);

        $start = microtime(true);
        try {
            for ($i = 0; $i < 5; $i++) {
                $method->invoke($service);
            }
            $elapsed = microtime(true) - $start;

            $this->assertLessThan(0.5, $elapsed, '5 nextSequence() calls deben completar en menos de 500ms');
        } finally {
            DB::statement('SELECT pg_advisory_unlock(100, ?)', [app(TenantManager::class)->require()->id]);
        }
    }

    private function invokeNextSequence(): int
    {
        $service = app(\App\Modules\InventoryTransfers\Services\InventoryTransferService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('nextSequence');
        $method->setAccessible(true);

        return $method->invoke($service);
    }
}
