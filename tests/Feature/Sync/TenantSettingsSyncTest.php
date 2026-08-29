<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Sync\Models\SyncNode;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Sync\Services\SyncInitialSnapshotService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\CompanySettings;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantSettingsSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_initial_snapshot_includes_company_settings(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Snapshot Empresa', 'slug' => 'snapshot-empresa']);
        app(TenantManager::class)->set($tenant);
        $this->configureCompany($tenant, [
            'razon_social' => 'Snapshot C.A.',
            'rif' => 'J-99999999-9',
        ]);
        $node = SyncNode::create([
            'code' => 'POS-EMPRESA',
            'name' => 'POS Empresa',
            'type' => 'local',
            'status' => 'active',
        ]);

        $summary = app(SyncInitialSnapshotService::class)->queueForNode($tenant, $node->id, 'POS-EMPRESA');

        $this->assertSame(1, $summary['events']['tenant_settings.updated']);
        $event = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'tenant_settings.updated')
            ->first();
        $this->assertNotNull($event, 'Falta el evento tenant_settings.updated en el snapshot');
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame('J-99999999-9', $payload['company']['rif']);
        $this->assertSame('Snapshot C.A.', $payload['company']['razon_social']);
    }

    public function test_company_settings_update_emits_outbox_event(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Emisor', 'slug' => 'emisor-empresa']);
        $user = $this->admin($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/tenant-settings', [
                'settings' => ['company' => ['rif' => 'J-88888888-8', 'razon_social' => 'Emisor C.A.']],
            ])
            ->assertOk();

        $event = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'tenant_settings.updated')
            ->first();
        $this->assertNotNull($event, 'No se emitio tenant_settings.updated en el outbox');
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame('J-88888888-8', $payload['company']['rif']);
        $this->assertSame('Emisor C.A.', $payload['company']['razon_social']);
    }

    public function test_applier_applies_company_settings_and_preserves_local_sections(): void
    {
        $tenantB = Tenant::create(['name' => 'Local B', 'slug' => 'local-b']);
        DB::table('tenant_settings')
            ->where('tenant_id', $tenantB->id)
            ->update(['settings' => json_encode(['telegram' => ['enabled' => true]])]);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenantB->id,
            'event_uuid' => Str::uuid()->toString(),
            'event_type' => 'tenant_settings.updated',
            'aggregate_type' => 'tenant_settings',
            'aggregate_id' => $tenantB->id,
            'payload_hash' => null,
            'payload' => json_encode([
                'tenant_id' => $tenantB->id,
                'company' => [
                    'razon_social' => 'Local B C.A.',
                    'rif' => 'J-77777777-7',
                    'show_on' => ['sale_ticket' => false],
                ],
            ]),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inboxRow = (array) DB::table('sync_inbox')->where('event_type', 'tenant_settings.updated')->first();
        app(SyncEventApplier::class)->applyOne($tenantB, $inboxRow);

        $stored = json_decode((string) DB::table('tenant_settings')->where('tenant_id', $tenantB->id)->value('settings'), true);
        $this->assertSame('J-77777777-7', $stored['company']['rif']);
        $this->assertSame('Local B C.A.', $stored['company']['razon_social']);
        $this->assertFalse($stored['company']['show_on']['sale_ticket']);
        $this->assertTrue($stored['company']['show_on']['guide']);
        $this->assertTrue($stored['telegram']['enabled'], 'Las secciones locales se deben preservar');
    }

    private function configureCompany(Tenant $tenant, array $company): void
    {
        DB::table('tenant_settings')
            ->updateOrInsert(
                ['tenant_id' => $tenant->id],
                ['settings' => json_encode(['company' => array_replace_recursive(CompanySettings::DEFAULTS, $company)])],
            );
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $role = Role::firstOrCreate(['name' => 'Admin Empresa', 'guard_name' => 'web']);
        $role->syncPermissions(['settings.manage']);
        $user->assignRole($role);

        return $user;
    }
}
