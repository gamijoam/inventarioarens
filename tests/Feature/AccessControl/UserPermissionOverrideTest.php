<?php

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\UserPermissionOverride;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserPermissionOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_replace_overrides_creates_allow_and_deny(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/overrides", [
                'items' => [
                    ['permission' => 'inventory.adjust', 'effect' => 'allow'],
                    ['permission' => 'sales.cancel', 'effect' => 'deny'],
                ],
            ])
            ->assertNoContent();

        $this->assertDatabaseHas('user_permission_overrides', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'permission' => 'inventory.adjust',
            'effect' => 'allow',
        ]);
        $this->assertDatabaseHas('user_permission_overrides', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'permission' => 'sales.cancel',
            'effect' => 'deny',
        ]);
    }

    public function test_replace_overrides_is_idempotent(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $items = [['permission' => 'inventory.adjust', 'effect' => 'allow']];

        // Primer PUT.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/overrides", ['items' => $items])
            ->assertNoContent();

        // Segundo PUT con los mismos items: debe seguir habiendo 1 fila.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/overrides", ['items' => $items])
            ->assertNoContent();

        $this->assertSame(1, UserPermissionOverride::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->count());
    }

    public function test_replace_overrides_rejects_unknown_permission(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/overrides", [
                'items' => [
                    ['permission' => 'made.up.permission', 'effect' => 'allow'],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_index_returns_overrides_grouped(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.view');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        UserPermissionOverride::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'permission' => 'sales.view',
            'effect' => 'allow',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/tenants/{$tenant->id}/users/{$user->id}/overrides")
            ->assertOk()
            ->json();

        $this->assertSame(1, $response['data']['extra_count']);
        $this->assertSame(0, $response['data']['deny_count']);
        $this->assertSame(['sales.view'], $response['data']['extras']);
    }

    public function test_destroy_removes_single_override(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        UserPermissionOverride::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'permission' => 'sales.view',
            'effect' => 'allow',
        ]);
        UserPermissionOverride::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'permission' => 'inventory.adjust',
            'effect' => 'deny',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/tenants/{$tenant->id}/users/{$user->id}/overrides/sales.view")
            ->assertNoContent();

        $this->assertDatabaseMissing('user_permission_overrides', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'permission' => 'sales.view',
        ]);
        $this->assertDatabaseHas('user_permission_overrides', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'permission' => 'inventory.adjust',
        ]);
    }

    public function test_effective_permissions_combines_roles_extras_minus_denies(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.view');
        $token = $this->makeTokenFor($admin, $tenant);

        // User con rol Vendedor + 1 extra (inventory.adjust) + 1 deny (sales.cancel).
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        setPermissionsTeamId($tenant->id);
        $role = Role::create(['name' => 'Vendedor', 'guard_name' => 'web', 'team_id' => $tenant->id]);
        $role->syncPermissions(['sales.view', 'sales.create', 'sales.cancel', 'pos.view']);
        $user->assignRole($role);

        UserPermissionOverride::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'permission' => 'inventory.adjust',
            'effect' => 'allow',
        ]);
        UserPermissionOverride::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'permission' => 'sales.cancel',
            'effect' => 'deny',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/tenants/{$tenant->id}/users/{$user->id}/effective-permissions")
            ->assertOk()
            ->json();

        // Base: 4 perms. Extra: +1 (inventory.adjust). Deny: -1 (sales.cancel). Final: 4.
        $this->assertSame(4, $response['data']['permission_count']);
        $this->assertContains('inventory.adjust', $response['data']['extras']);
        $this->assertContains('sales.cancel', $response['data']['denied']);
        $this->assertNotContains('sales.cancel', $response['data']['permissions']);
        $this->assertContains('inventory.adjust', $response['data']['permissions']);
    }

    public function test_replace_overrides_requires_users_update(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $viewer = User::factory()->create(['password' => 'secret123']);
        $viewer->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $viewer->givePermissionTo('users.view');
        $token = $this->makeTokenFor($viewer, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/overrides", [
                'items' => [
                    ['permission' => 'sales.view', 'effect' => 'allow'],
                ],
            ])
            ->assertForbidden();
    }

    public function test_index_requires_users_view(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        // No le damos users.view.
        $token = $this->makeTokenFor($user, $tenant);

        $other = User::factory()->create();
        $other->tenants()->attach($tenant, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/tenants/{$tenant->id}/users/{$other->id}/overrides")
            ->assertForbidden();
    }

    public function test_replace_overrides_rejects_cross_tenant_user(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);

        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenantB, ['status' => 'active']);
        $this->seedPermissions($tenantB);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenantB);

        $other = User::factory()->create();
        $other->tenants()->attach($tenantA, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->putJson("/api/tenants/{$tenantB->id}/users/{$other->id}/overrides", [
                'items' => [['permission' => 'sales.view', 'effect' => 'allow']],
            ])
            ->assertStatus(404);
    }

    private function seedPermissions(Tenant $tenant): void
    {
        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (\App\Support\Permissions\BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeTokenFor(User $user, Tenant $tenant): string
    {
        $plainToken = Str::random(80);
        AuthToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $plainToken;
    }
}