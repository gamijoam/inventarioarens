<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_or_updates_sync_node_for_current_tenant(): void
    {
        [$tenant, $user] = $this->tenantUser('empresa-sync');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/nodes', [
                'code' => 'LOCAL-CCS-01',
                'name' => 'Caja local Caracas',
                'type' => 'local',
                'metadata' => ['app' => 'desktop', 'version' => '1.0.0'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'LOCAL-CCS-01')
            ->assertJsonPath('data.metadata.app', 'desktop');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/nodes', [
                'code' => 'LOCAL-CCS-01',
                'name' => 'Caja local Caracas actualizada',
                'type' => 'local',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Caja local Caracas actualizada');

        $this->assertSame(1, DB::table('sync_nodes')->where('tenant_id', $tenant->id)->count());
    }

    public function test_it_receives_pushed_events_idempotently(): void
    {
        [$tenant, $user] = $this->tenantUser('empresa-push');
        $nodeId = $this->node($tenant, 'LOCAL-VAL-01');
        $eventUuid = (string) Str::uuid();

        $payload = [
            'origin_node_code' => 'LOCAL-VAL-01',
            'events' => [[
                'event_uuid' => $eventUuid,
                'event_type' => 'pos.order.paid',
                'aggregate_type' => 'pos_order',
                'aggregate_id' => 10,
                'payload' => ['order_id' => 10, 'total_base_amount' => '20.0000'],
            ]],
        ];

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/events/push', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.received', 1)
            ->assertJsonPath('data.duplicated', 0);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/events/push', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.received', 0)
            ->assertJsonPath('data.duplicated', 1);

        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'origin_node_id' => $nodeId,
            'event_uuid' => $eventUuid,
            'status' => 'received',
        ]);
        $this->assertSame(1, DB::table('sync_inbox')->where('tenant_id', $tenant->id)->count());
    }

    public function test_it_pulls_pending_events_and_acknowledges_them(): void
    {
        [$tenant, $user] = $this->tenantUser('empresa-pull');
        $localNodeId = $this->node($tenant, 'LOCAL-CCS-01');
        $cloudNodeId = $this->node($tenant, 'CLOUD-MAIN', 'cloud');
        $eventUuid = (string) Str::uuid();
        $now = now();

        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'origin_node_id' => $cloudNodeId,
            'target_node_id' => $localNodeId,
            'target_scope' => 'tenant',
            'event_type' => 'price.updated',
            'aggregate_type' => 'product_price',
            'aggregate_id' => 55,
            'payload' => json_encode(['product_id' => 55, 'price' => '15.5000']),
            'occurred_at' => $now,
            'available_at' => $now,
            'status' => 'pending',
            'idempotency_key' => 'cloud-price-55',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/sync/events/pull?node_code=LOCAL-CCS-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_uuid', $eventUuid)
            ->assertJsonPath('data.0.payload.price', '15.5000');

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'attempts' => 1,
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/sync/events/{$eventUuid}/ack", [
                'node_code' => 'LOCAL-CCS-01',
                'status' => 'applied',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'status' => 'processed',
        ]);
        $this->assertDatabaseHas('sync_states', [
            'tenant_id' => $tenant->id,
            'node_id' => $localNodeId,
            'direction' => 'pull',
            'last_event_uuid' => $eventUuid,
        ]);
    }

    public function test_sync_events_are_isolated_by_tenant(): void
    {
        [$tenantA, $userA] = $this->tenantUser('empresa-sync-a');
        [$tenantB, $userB] = $this->tenantUser('empresa-sync-b');
        $this->node($tenantA, 'LOCAL-01');
        $this->node($tenantB, 'LOCAL-01');

        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenantA->id,
            'event_uuid' => (string) Str::uuid(),
            'target_scope' => 'tenant',
            'event_type' => 'price.updated',
            'aggregate_type' => 'product_price',
            'aggregate_id' => 99,
            'payload' => json_encode(['tenant' => 'A']),
            'occurred_at' => now(),
            'available_at' => now(),
            'status' => 'pending',
            'idempotency_key' => 'tenant-a-only',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/sync/events/pull?node_code=LOCAL-01')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/sync/events/pull?node_code=LOCAL-01')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    private function tenantUser(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
        ]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['status' => 'active']);

        return [$tenant, $user];
    }

    private function node(Tenant $tenant, string $code, string $type = 'local'): int
    {
        return (int) DB::table('sync_nodes')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'status' => 'active',
            'metadata' => json_encode([]),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
