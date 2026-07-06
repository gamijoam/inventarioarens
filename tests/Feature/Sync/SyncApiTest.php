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
            'status' => 'ignored',
        ]);
        $this->assertSame(1, DB::table('sync_inbox')->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'origin_node_id' => $nodeId,
            'event_uuid' => $eventUuid,
            'status' => 'pending',
        ]);
    }

    public function test_pushed_product_update_is_applied_to_cloud_database_and_relayed(): void
    {
        [$tenant, $user] = $this->tenantUser('empresa-push-product');
        $nodeId = $this->node($tenant, 'LOCAL-VAL-01');
        $eventUuid = (string) Str::uuid();
        $now = now();

        DB::table('products')->insert([
            'tenant_id' => $tenant->id,
            'name' => 'Adaptador Bluetooth',
            'sku' => 'ADP-BT-CCS',
            'tracking_type' => 'quantity',
            'base_price' => '20.0000',
            'sale_currency' => 'USD',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payload = [
            'origin_node_code' => 'LOCAL-VAL-01',
            'events' => [[
                'event_uuid' => $eventUuid,
                'event_type' => 'product.updated',
                'aggregate_type' => 'product',
                'aggregate_id' => 10,
                'payload' => [
                    'sku' => 'ADP-BT-CCS',
                    'name' => 'Adaptador Bluetooth',
                    'tracking_type' => 'quantity',
                    'base_price' => '1000.0000',
                    'sale_currency' => 'USD',
                    'is_active' => true,
                ],
                'occurred_at' => $now->toISOString(),
            ]],
        ];

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/events/push', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.received', 1)
            ->assertJsonPath('data.applied', 1)
            ->assertJsonPath('data.failed', 0);

        $this->assertDatabaseHas('products', [
            'tenant_id' => $tenant->id,
            'sku' => 'ADP-BT-CCS',
            'base_price' => '1000.0000',
        ]);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'origin_node_id' => $nodeId,
            'event_uuid' => $eventUuid,
            'status' => 'applied',
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'origin_node_id' => $nodeId,
            'event_uuid' => $eventUuid,
            'status' => 'pending',
        ]);
    }

    public function test_pushed_product_update_is_applied_even_when_older_inbox_events_are_pending(): void
    {
        [$tenant, $user] = $this->tenantUser('empresa-push-product-backlog');
        $nodeId = $this->node($tenant, 'LOCAL-VAL-01');
        $oldEventUuid = (string) Str::uuid();
        $newEventUuid = (string) Str::uuid();
        $now = now();

        DB::table('products')->insert([
            'tenant_id' => $tenant->id,
            'name' => 'Adaptador Bluetooth',
            'sku' => 'ADP-BT-VAL',
            'tracking_type' => 'quantity',
            'base_price' => '20.0000',
            'sale_currency' => 'USD',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $oldEventUuid,
            'origin_node_id' => $nodeId,
            'event_type' => 'pos.order.paid',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => 99,
            'payload_hash' => hash('sha256', json_encode(['order_id' => 99])),
            'payload' => json_encode(['order_id' => 99]),
            'status' => 'received',
            'received_at' => $now->copy()->subMinute(),
            'created_at' => $now->copy()->subMinute(),
            'updated_at' => $now->copy()->subMinute(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/events/push', [
                'origin_node_code' => 'LOCAL-VAL-01',
                'events' => [[
                    'event_uuid' => $newEventUuid,
                    'event_type' => 'product.updated',
                    'aggregate_type' => 'product',
                    'aggregate_id' => 67,
                    'payload' => [
                        'sku' => 'ADP-BT-VAL',
                        'name' => 'Adaptador Bluetooth',
                        'tracking_type' => 'quantity',
                        'base_price' => '2000.0000',
                        'sale_currency' => 'USD',
                        'is_active' => true,
                    ],
                    'occurred_at' => $now->toISOString(),
                ]],
            ])
            ->assertAccepted()
            ->assertJsonPath('data.received', 1)
            ->assertJsonPath('data.applied', 1)
            ->assertJsonPath('data.failed', 0);

        $this->assertDatabaseHas('products', [
            'tenant_id' => $tenant->id,
            'sku' => 'ADP-BT-VAL',
            'base_price' => '2000.0000',
        ]);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $oldEventUuid,
            'status' => 'received',
        ]);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $newEventUuid,
            'status' => 'applied',
        ]);
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

    public function test_status_returns_local_event_counters_and_latest_events(): void
    {
        [$tenant, $user] = $this->tenantUser('empresa-sync-status');
        $nodeId = $this->node($tenant, 'LOCAL-STATUS-01');
        $now = now();

        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'origin_node_id' => $nodeId,
            'target_scope' => 'tenant',
            'event_type' => 'product.price.updated',
            'aggregate_type' => 'product',
            'aggregate_id' => 501,
            'payload' => json_encode(['product_id' => 501, 'price' => '77.7700']),
            'occurred_at' => $now,
            'available_at' => $now,
            'status' => 'pending',
            'idempotency_key' => 'status-outbox-501',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'origin_node_id' => $nodeId,
            'event_type' => 'pos.order.paid',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => 901,
            'payload_hash' => hash('sha256', json_encode(['order_id' => 901])),
            'payload' => json_encode(['order_id' => 901]),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/sync/status')
            ->assertOk()
            ->assertJsonPath('data.outbox.pending', 1)
            ->assertJsonPath('data.inbox.received', 1)
            ->assertJsonPath('data.latest_events.outbox.0.event_type', 'product.price.updated')
            ->assertJsonPath('data.latest_events.outbox.0.payload.price', '77.7700')
            ->assertJsonPath('data.latest_events.inbox.0.event_type', 'pos.order.paid')
            ->assertJsonPath('data.latest_events.inbox.0.payload.order_id', 901);
    }

    public function test_local_readiness_is_tracked_by_tenant_and_installation(): void
    {
        [$tenantA, $userA] = $this->tenantUser('empresa-readiness-a');
        [$tenantB, $userB] = $this->tenantUser('empresa-readiness-b');
        $installationCode = 'LOCAL-MOSTRADOR-01';

        $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/sync/local-readiness?installation_code={$installationCode}")
            ->assertOk()
            ->assertJsonPath('data.installation_code', $installationCode)
            ->assertJsonPath('data.status', 'pending');

        $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/sync/local-readiness', [
                'installation_code' => $installationCode,
                'node_code' => 'LOCAL-NORTE-01',
                'node_name' => 'Equipo mostrador norte',
                'status' => 'ready',
                'metadata' => ['scope' => 'manual-test'],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.node_code', 'LOCAL-NORTE-01')
            ->assertJsonPath('data.metadata.scope', 'manual-test');

        $this->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson("/api/sync/local-readiness?installation_code={$installationCode}")
            ->assertOk()
            ->assertJsonPath('data.installation_code', $installationCode)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.node_code', null);

        $this->assertDatabaseHas('sync_tenant_readiness', [
            'tenant_id' => $tenantA->id,
            'installation_code' => $installationCode,
            'status' => 'ready',
        ]);
        $this->assertDatabaseHas('sync_tenant_readiness', [
            'tenant_id' => $tenantB->id,
            'installation_code' => $installationCode,
            'status' => 'pending',
        ]);
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
