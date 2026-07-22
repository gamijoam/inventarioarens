<?php

namespace Tests\Feature\DataImport;

use App\Models\User;
use App\Modules\DataImport\Models\DataImport;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DataImportPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('data_import.view', 'web');
        Permission::findOrCreate('data_import.create', 'web');
        Permission::findOrCreate('data_import.execute', 'web');
        Permission::findOrCreate('data_import.delete', 'web');
    }

    private function makeTenant(string $slug = 'tenant-a'): Tenant
    {
        return Tenant::create([
            'name' => 'Tenant A',
            'slug' => $slug,
            'is_group' => false,
            'parent_id' => null,
        ]);
    }

    private function makeAdmin(Tenant $tenant, array $perms = []): User
    {
        $user = User::create([
            'name' => 'Admin '.$tenant->slug,
            'email' => 'admin@'.$tenant->slug.'.test',
            'password' => bcrypt('secret123'),
        ]);

        $user->tenants()->attach($tenant->id, ['status' => 'active']);

        setPermissionsTeamId($tenant->id);
        if (! empty($perms)) {
            $user->givePermissionTo($perms);
        }

        return $user;
    }

    public function test_admin_can_create_data_import_session(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $admin = $this->makeAdmin($tenant, ['data_import.create', 'data_import.view']);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/import/sessions', [
                'meta' => ['source' => 'manual-test'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.user_id', $admin->id);

        $this->assertDatabaseHas('data_imports', [
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'status' => 'pending',
        ]);
    }

    public function test_user_without_create_permission_cannot_create_session(): void
    {
        $tenant = $this->makeTenant('tenant-b');
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $viewer = $this->makeAdmin($tenant, ['data_import.view']);

        $response = $this
            ->actingAs($viewer)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/import/sessions', []);

        $response->assertForbidden();
    }

    public function test_session_index_only_returns_sessions_of_current_tenant(): void
    {
        $tenantA = $this->makeTenant('tenant-a');
        $tenantB = $this->makeTenant('tenant-b');

        app(TenantManager::class)->set($tenantA);
        setPermissionsTeamId($tenantA->id);
        $adminA = $this->makeAdmin($tenantA, ['data_import.create', 'data_import.view']);
        $sessionA = DataImport::create([
            'user_id' => $adminA->id,
            'status' => 'pending',
        ]);

        app(TenantManager::class)->set($tenantB);
        setPermissionsTeamId($tenantB->id);
        $adminB = $this->makeAdmin($tenantB, ['data_import.create', 'data_import.view']);
        $sessionB = DataImport::create([
            'user_id' => $adminB->id,
            'status' => 'pending',
        ]);

        app(TenantManager::class)->set($tenantA);
        setPermissionsTeamId($tenantA->id);

        $response = $this
            ->actingAs($adminA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/import/sessions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sessionA->id)
            ->assertJsonMissing(['id' => $sessionB->id]);
    }

    public function test_admin_cannot_delete_session_in_other_tenant(): void
    {
        $tenantA = $this->makeTenant('tenant-a');
        $tenantB = $this->makeTenant('tenant-b');

        app(TenantManager::class)->set($tenantA);
        setPermissionsTeamId($tenantA->id);
        $adminA = $this->makeAdmin($tenantA, ['data_import.create', 'data_import.delete']);

        app(TenantManager::class)->set($tenantB);
        setPermissionsTeamId($tenantB->id);
        $adminB = $this->makeAdmin($tenantB, ['data_import.create', 'data_import.delete']);
        $sessionB = DataImport::create([
            'user_id' => $adminB->id,
            'status' => 'completed',
        ]);

        app(TenantManager::class)->set($tenantA);
        setPermissionsTeamId($tenantA->id);

        $response = $this
            ->actingAs($adminA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->deleteJson("/api/import/sessions/{$sessionB->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('data_imports', ['id' => $sessionB->id]);
    }

    public function test_platform_admin_without_tenant_membership_is_rejected_by_tenant_middleware(): void
    {
        $tenantA = $this->makeTenant('tenant-a');

        app(TenantManager::class)->set($tenantA);
        setPermissionsTeamId($tenantA->id);
        $adminA = $this->makeAdmin($tenantA, ['data_import.create', 'data_import.delete']);
        $session = DataImport::create([
            'user_id' => $adminA->id,
            'status' => 'completed',
        ]);

        $platform = User::create([
            'name' => 'Platform Admin',
            'email' => 'platform@test.test',
            'password' => bcrypt('secret123'),
            'is_platform_admin' => true,
        ]);

        app(TenantManager::class)->set($tenantA);
        setPermissionsTeamId($tenantA->id);
        $platform->givePermissionTo(['data_import.view', 'data_import.delete']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this
            ->actingAs($platform)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->deleteJson("/api/import/sessions/{$session->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('data_imports', ['id' => $session->id]);
    }

    public function test_active_session_cannot_be_deleted(): void
    {
        $tenant = $this->makeTenant('tenant-active');
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $admin = $this->makeAdmin($tenant, ['data_import.create', 'data_import.delete']);
        $session = DataImport::create([
            'user_id' => $admin->id,
            'status' => 'running',
        ]);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/import/sessions/{$session->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('data_imports', ['id' => $session->id]);
    }
}
