<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IssueSyncTokenPermissionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (\App\Support\Permissions\BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function makeUserInTenant(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $role = Role::findOrCreate('Test Role '.uniqid(), 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    public function test_user_without_sync_issue_token_permission_cannot_issue_token(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Sync', 'slug' => 'tienda-sync-1']);
        $user = $this->makeUserInTenant($tenant, [
            'sales.view',
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/tokens', [
                'name' => 'test-worker',
                'days' => 30,
            ]);

        $response->assertStatus(403);
    }

    public function test_user_with_sync_issue_token_permission_can_issue_token(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Sync 2', 'slug' => 'tienda-sync-2']);
        $user = $this->makeUserInTenant($tenant, [
            'sync.issue_token',
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/tokens', [
                'name' => 'test-worker',
                'days' => 30,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.token', fn ($t) => is_string($t) && strlen($t) > 20);
    }

    public function test_days_over_365_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Sync 3', 'slug' => 'tienda-sync-3']);
        $user = $this->makeUserInTenant($tenant, [
            'sync.issue_token',
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/tokens', [
                'name' => 'test-worker',
                'days' => 366,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['days']);
    }

    public function test_days_exactly_365_accepted(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Sync 4', 'slug' => 'tienda-sync-4']);
        $user = $this->makeUserInTenant($tenant, [
            'sync.issue_token',
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/tokens', [
                'name' => 'test-worker',
                'days' => 365,
            ]);

        $response->assertCreated();
    }

    public function test_days_zero_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Sync 5', 'slug' => 'tienda-sync-5']);
        $user = $this->makeUserInTenant($tenant, [
            'sync.issue_token',
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/tokens', [
                'name' => 'test-worker',
                'days' => 0,
            ]);

        $response->assertStatus(422);
    }

    public function test_days_null_uses_default_90(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Sync 6', 'slug' => 'tienda-sync-6']);
        $user = $this->makeUserInTenant($tenant, [
            'sync.issue_token',
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/tokens', [
                'name' => 'test-worker',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.expires_at', fn ($v) => is_string($v));
    }
}