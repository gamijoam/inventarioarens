<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncWorkerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_worker_pushes_local_events_and_pulls_cloud_events(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Sync Worker',
            'slug' => 'empresa-sync-worker',
        ]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['status' => 'active']);

        $localEventUuid = (string) Str::uuid();
        $cloudEventUuid = (string) Str::uuid();
        $now = now();

        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $localEventUuid,
            'target_scope' => 'tenant',
            'event_type' => 'pos.order.paid',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => 15,
            'payload' => json_encode(['order_id' => 15, 'total_base_amount' => '20.0000']),
            'occurred_at' => $now,
            'available_at' => $now,
            'status' => 'pending',
            'idempotency_key' => 'pos-order-15',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Http::fake([
            'https://cloud.test/api/sync/nodes' => Http::response([
                'data' => ['code' => 'LOCAL-VAL-01'],
            ], 201),
            'https://cloud.test/api/sync/events/push' => Http::response([
                'data' => ['received' => 1, 'duplicated' => 0],
            ], 202),
            'https://cloud.test/api/sync/events/pull*' => Http::response([
                'data' => [[
                    'id' => 99,
                    'event_uuid' => $cloudEventUuid,
                    'event_type' => 'price.updated',
                    'aggregate_type' => 'product_price',
                    'aggregate_id' => 44,
                    'payload' => ['product_id' => 44, 'price' => '30.0000'],
                ]],
            ], 200),
            "https://cloud.test/api/sync/events/{$cloudEventUuid}/ack" => Http::response([
                'data' => ['event_uuid' => $cloudEventUuid, 'status' => 'processed'],
            ], 200),
        ]);

        $this->artisan('sync:run', [
            'tenant' => $tenant->slug,
            '--node' => 'LOCAL-VAL-01',
            '--name' => 'Local Valencia 01',
            '--cloud-url' => 'https://cloud.test/api',
            '--token' => 'token-demo',
            '--limit' => 10,
        ])
            ->expectsOutput('Sincronizacion ejecutada.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $localEventUuid,
            'status' => 'processed',
        ]);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $cloudEventUuid,
            'event_type' => 'price.updated',
            'status' => 'received',
        ]);
        $this->assertDatabaseHas('sync_nodes', [
            'tenant_id' => $tenant->id,
            'code' => 'LOCAL-VAL-01',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('sync_states', [
            'tenant_id' => $tenant->id,
            'direction' => 'push',
            'last_event_uuid' => $localEventUuid,
        ]);
        $this->assertDatabaseHas('sync_states', [
            'tenant_id' => $tenant->id,
            'direction' => 'pull',
            'last_event_uuid' => $cloudEventUuid,
        ]);

        Http::assertSentCount(4);
    }

    public function test_sync_worker_does_not_run_for_unknown_tenant(): void
    {
        Http::fake();

        $this->artisan('sync:run', [
            'tenant' => 'empresa-inexistente',
            '--cloud-url' => 'https://cloud.test/api',
            '--token' => 'token-demo',
        ])
            ->expectsOutput('No se encontro la empresa indicada.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }
}
