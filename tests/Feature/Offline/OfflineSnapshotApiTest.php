<?php

namespace Tests\Feature\Offline;

use App\Models\User;
use App\Modules\Offline\Jobs\BuildOfflineSnapshot;
use App\Modules\Offline\Services\OfflineSnapshotStore;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OfflineSnapshotApiTest extends TestCase
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

    public function test_offline_downloads_require_authentication_even_when_a_slug_is_supplied(): void
    {
        $this->getJson('/api/offline/package?slug=balanzapro')
            ->assertUnauthorized();

        $this->postJson('/api/offline/snapshots', ['slug' => 'balanzapro'])
            ->assertUnauthorized();
    }

    public function test_settings_manager_can_request_a_private_snapshot_for_the_active_tenant(): void
    {
        Queue::fake();
        $tenant = Tenant::create(['name' => 'Balanza Pro', 'slug' => 'balanzapro']);
        $user = $this->userInTenant($tenant);
        $this->grantPermission($tenant, $user, 'settings.manage');

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/offline/snapshots');

        $response->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('status_url', fn ($value) => str_contains($value, '/api/offline/snapshots/'));

        $snapshotId = $response->json('id');
        $metadata = app(OfflineSnapshotStore::class)->load($snapshotId);
        $this->assertSame($tenant->id, $metadata['tenant_id']);
        $this->assertSame($user->id, $metadata['user_id']);
        Queue::assertPushed(BuildOfflineSnapshot::class, fn ($job) => $job->snapshotId === $snapshotId);
        @unlink(app(OfflineSnapshotStore::class)->metadataPath($snapshotId));
    }

    public function test_user_without_settings_permission_cannot_export_or_download_installer(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa']);
        $user = $this->userInTenant($tenant);
        $this->grantPermission($tenant, $user, 'products.view');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/offline/installer-info')
            ->assertForbidden();

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/offline/snapshots')
            ->assertForbidden();
    }

    public function test_snapshot_status_is_private_to_the_requesting_user(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa']);
        $owner = $this->userInTenant($tenant);
        $otherUser = $this->userInTenant($tenant);
        $this->grantPermission($tenant, $owner, 'settings.manage');
        $this->grantPermission($tenant, $otherUser, 'settings.manage');

        $id = (string) Str::uuid();
        app(OfflineSnapshotStore::class)->create($id, $tenant->id, $owner->id);

        $this->actingAs($otherUser)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/offline/snapshots/'.$id)
            ->assertNotFound();

        @unlink(app(OfflineSnapshotStore::class)->metadataPath($id));
    }

    public function test_cloud_sales_upload_is_disabled(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa']);
        $user = $this->userInTenant($tenant);
        $this->grantPermission($tenant, $user, 'settings.manage');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/offline/upload-sales', ['tenant_slug' => $tenant->slug, 'sales' => []])
            ->assertStatus(410);
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantPermission(Tenant $tenant, User $user, string $permission): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $role = Role::findOrCreate('Offline '.$permission, 'web');
        $role->syncPermissions([$permission]);
        $user->assignRole($role);
    }
}
