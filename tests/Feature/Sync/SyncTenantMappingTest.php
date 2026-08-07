<?php

namespace Tests\Feature\Sync;

use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncTenantMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_local_persists_the_remote_tenant_mapping(): void
    {
        $previousPassword = getenv('SYNC_BOOTSTRAP_PASSWORD');
        putenv('SYNC_BOOTSTRAP_PASSWORD=local-password-123');

        try {
            $this->artisan('sync:prepare-local', [
                'tenant_slug' => 'yaracall-local',
                'tenant_name' => 'Yaracall Local',
                'email' => 'yaracall@local.test',
                '--remote-tenant-id' => 3,
                '--remote-parent-id' => 2,
                '--remote-is-group' => false,
            ])->assertExitCode(0);
        } finally {
            $previousPassword === false
                ? putenv('SYNC_BOOTSTRAP_PASSWORD')
                : putenv('SYNC_BOOTSTRAP_PASSWORD='.$previousPassword);
        }

        $this->assertDatabaseHas('sync_tenant_mappings', [
            'remote_tenant_id' => 3,
            'remote_parent_id' => 2,
            'remote_slug' => 'yaracall-local',
            'is_group' => false,
        ]);

        $this->assertSame(
            1,
            DB::table('sync_tenant_mappings')->where('remote_tenant_id', 3)->count(),
        );
    }

    public function test_product_event_records_remote_to_local_entity_mapping(): void
    {
        $tenant = Tenant::create([
            'name' => 'Mapped Local',
            'slug' => 'mapped-local',
            'status' => 'active',
        ]);

        DB::table('sync_tenant_mappings')->insert([
            'local_tenant_id' => $tenant->id,
            'remote_tenant_id' => 901,
            'remote_slug' => 'mapped-remote',
            'is_group' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TenantManager::class)->set($tenant);
        $inboxId = DB::table('sync_inbox')->insertGetId([
            'tenant_id' => $tenant->id,
            'event_uuid' => '90111111-1111-1111-1111-111111111111',
            'event_type' => 'product.created',
            'aggregate_type' => 'product',
            'aggregate_id' => 7001,
            'payload' => json_encode([
                'id' => 7001,
                'name' => 'Remote Product',
                'sku' => 'REMOTE-7001',
                'tracking_type' => 'quantity',
                'base_price' => 10,
            ]),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncEventApplier::class)->applyOne($tenant, (array) DB::table('sync_inbox')->find($inboxId));

        $localProductId = DB::table('products')->where('tenant_id', $tenant->id)->value('id');
        $this->assertDatabaseHas('sync_entity_mappings', [
            'entity_type' => 'product',
            'remote_tenant_id' => 901,
            'remote_id' => 7001,
            'local_tenant_id' => $tenant->id,
            'local_id' => $localProductId,
            'remote_key' => 'REMOTE-7001',
        ]);
    }
}
