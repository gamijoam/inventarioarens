<?php

namespace Tests\Feature\Sync;

use App\Modules\Sync\Services\SyncBootstrapImporter;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncBootstrapImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_snapshot_events_into_local_sqlite_and_records_mapping_and_timestamps(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa local',
            'slug' => 'empresa-local-bootstrap',
            'status' => 'active',
        ]);
        DB::table('sync_tenant_mappings')->insert([
            'local_tenant_id' => $tenant->id,
            'remote_tenant_id' => 901,
            'remote_slug' => 'empresa-remota-bootstrap',
            'is_group' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $createdAt = now()->subMinute()->toISOString();
        app(TenantManager::class)->set($tenant);

        $summary = app(SyncBootstrapImporter::class)->importSnapshot($tenant, [
            'version' => 1,
            'events' => [[
                'id' => 7001,
                'event_uuid' => '90111111-1111-1111-1111-111111111111',
                'event_type' => 'product.created',
                'aggregate_type' => 'product',
                'aggregate_id' => 7001,
                'payload' => [
                    'sku' => 'BOOT-REMOTE-001',
                    'name' => 'Producto remoto bootstrap',
                    'tracking_type' => 'quantity',
                    'base_price' => '12.5000',
                    'sale_currency' => 'USD',
                    'is_active' => true,
                ],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]],
        ]);

        $this->assertSame(1, $summary['applied']);
        $this->assertSame(0, $summary['failed']);
        $this->assertNotNull(DB::table('products')->where('tenant_id', $tenant->id)->value('created_at'));
        $this->assertNotNull(DB::table('products')->where('tenant_id', $tenant->id)->value('updated_at'));
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => '90111111-1111-1111-1111-111111111111',
            'status' => 'applied',
        ]);
        $this->assertDatabaseHas('sync_entity_mappings', [
            'entity_type' => 'product',
            'remote_tenant_id' => 901,
            'remote_id' => 7001,
            'local_tenant_id' => $tenant->id,
        ]);
    }
}
