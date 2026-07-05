<?php

namespace Tests\Feature\Sync;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_tables_store_nodes_outbox_inbox_and_state_per_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Sync',
            'slug' => 'tenant-sync',
        ]);
        $now = now();

        $this->assertTrue(Schema::hasTable('sync_nodes'));
        $this->assertTrue(Schema::hasTable('sync_outbox'));
        $this->assertTrue(Schema::hasTable('sync_inbox'));
        $this->assertTrue(Schema::hasTable('sync_states'));

        $nodeId = DB::table('sync_nodes')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'LOCAL-CCS-01',
            'name' => 'Local Caracas 01',
            'type' => 'local',
            'status' => 'active',
            'metadata' => json_encode(['version' => 'desktop']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $eventUuid = (string) Str::uuid();

        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'origin_node_id' => $nodeId,
            'target_scope' => 'tenant',
            'event_type' => 'price.updated',
            'aggregate_type' => 'product_price',
            'aggregate_id' => 99,
            'payload' => json_encode(['product_id' => 99, 'price' => 15.5]),
            'occurred_at' => $now,
            'available_at' => $now,
            'status' => 'pending',
            'idempotency_key' => 'price.updated:99:1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'origin_node_id' => $nodeId,
            'event_type' => 'price.updated',
            'aggregate_type' => 'product_price',
            'aggregate_id' => 99,
            'payload_hash' => hash('sha256', 'price.updated:99:1'),
            'payload' => json_encode(['product_id' => 99, 'price' => 15.5]),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('sync_states')->insert([
            'tenant_id' => $tenant->id,
            'node_id' => $nodeId,
            'direction' => 'pull',
            'last_event_uuid' => $eventUuid,
            'last_success_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertDatabaseHas('sync_nodes', [
            'tenant_id' => $tenant->id,
            'code' => 'LOCAL-CCS-01',
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'status' => 'received',
        ]);
        $this->assertDatabaseHas('sync_states', [
            'tenant_id' => $tenant->id,
            'node_id' => $nodeId,
            'direction' => 'pull',
        ]);
    }
}
