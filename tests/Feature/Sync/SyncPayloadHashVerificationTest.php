<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SyncPayloadHashVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_with_matching_payload_hash_applies_normally(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Hash OK', 'slug' => 'tienda-hash-ok']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $payload = ['name' => 'Test Branch', 'code' => 'TEST-BRANCH'];
        $rawJson = json_encode($payload);
        $eventUuid = '00000000-0000-0000-0000-000000000001';

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'origin_node_id' => null,
            'event_type' => 'branch.created',
            'aggregate_type' => 'branch',
            'aggregate_id' => null,
            'payload' => $rawJson,
            'payload_hash' => hash('sha256', $rawJson),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = DB::table('sync_inbox')->where('event_uuid', $eventUuid)->first();

        app(SyncEventApplier::class)->applyOne($tenant, (array) $event);

        $updated = DB::table('sync_inbox')->where('event_uuid', $eventUuid)->first();
        $this->assertSame('applied', $updated->status);
        $this->assertNotNull($updated->applied_at);
    }

    public function test_event_with_tampered_payload_throws_and_marks_as_failed(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Hash FAIL', 'slug' => 'tienda-hash-fail']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $originalPayload = ['name' => 'Original Name', 'code' => 'ORIG-CODE'];
        $tamperedPayload = ['name' => 'TAMPERED Name', 'code' => 'ORIG-CODE'];

        $rawOriginal = json_encode($originalPayload);
        $rawTampered = json_encode($tamperedPayload);

        $eventUuid = '00000000-0000-0000-0000-000000000002';

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'origin_node_id' => null,
            'event_type' => 'branch.created',
            'aggregate_type' => 'branch',
            'aggregate_id' => null,
            'payload' => $rawTampered,
            'payload_hash' => hash('sha256', $rawOriginal),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = DB::table('sync_inbox')->where('event_uuid', $eventUuid)->first();

        $threw = false;
        try {
            app(SyncEventApplier::class)->applyOne($tenant, (array) $event);
        } catch (RuntimeException $exception) {
            $threw = true;
            $this->assertStringContainsString('Payload hash mismatch', $exception->getMessage());
            $this->assertStringContainsString('branch.created', $exception->getMessage());
        }

        $this->assertTrue($threw, 'applyOne debe lanzar RuntimeException cuando el payload fue alterado');

        $row = DB::table('sync_inbox')->where('event_uuid', $eventUuid)->first();
        $this->assertSame('received', $row->status, 'Estado NO debe cambiar (applyOne lanzó antes de update)');

        $this->assertDatabaseMissing('branches', [
            'tenant_id' => $tenant->id,
            'code' => 'ORIG-CODE',
        ]);
    }

    public function test_event_without_payload_hash_skips_verification(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Legacy', 'slug' => 'tienda-legacy']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $payload = ['name' => 'Legacy Branch', 'code' => 'LEGACY-CODE'];
        $rawJson = json_encode($payload);

        $eventUuid = '00000000-0000-0000-0000-000000000003';

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'origin_node_id' => null,
            'event_type' => 'branch.created',
            'aggregate_type' => 'branch',
            'aggregate_id' => null,
            'payload' => $rawJson,
            'payload_hash' => null,
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = DB::table('sync_inbox')->where('event_uuid', $eventUuid)->first();

        app(SyncEventApplier::class)->applyOne($tenant, (array) $event);

        $updated = DB::table('sync_inbox')->where('event_uuid', $eventUuid)->first();
        $this->assertSame('applied', $updated->status, 'Eventos legacy sin hash deben procesarse (backward compat)');
    }

    public function test_apply_events_wrapper_marks_tampered_events_as_failed(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Wrapper', 'slug' => 'tienda-wrapper']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $tamperedPayload = ['name' => 'Tampered', 'code' => 'WRONG'];
        $validHash = hash('sha256', json_encode(['name' => 'Original', 'code' => 'WRONG']));

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => '00000000-0000-0000-0000-000000000004',
            'origin_node_id' => null,
            'event_type' => 'branch.created',
            'aggregate_type' => 'branch',
            'aggregate_id' => null,
            'payload' => json_encode($tamperedPayload),
            'payload_hash' => $validHash,
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = DB::table('sync_inbox')->where('event_uuid', '00000000-0000-0000-0000-000000000004')->first();

        $reflection = new \ReflectionClass(SyncEventApplier::class);
        $method = $reflection->getMethod('applyEvents');
        $method->setAccessible(true);

        $summary = $method->invoke(app(SyncEventApplier::class), $tenant, [$event]);

        $this->assertSame(0, $summary['applied']);
        $this->assertSame(1, $summary['failed']);

        $row = DB::table('sync_inbox')->where('event_uuid', '00000000-0000-0000-0000-000000000004')->first();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('Payload hash mismatch', $row->last_error);
    }
}