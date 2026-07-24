<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CrossTenantUserAttachTest extends TestCase
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

    public function test_attach_existing_user_to_another_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);

        $existing = User::factory()->create(['email' => 'cross@example.test']);
        $existing->tenants()->attach($tenantA, ['status' => 'active']);

        $actor = $this->makeActorWithToken(['tenants.users.attach']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson("/api/tenants/{$tenantB->id}/users", [
                'user_id' => $existing->id,
                'roles' => ['Vendedor'],
            ])
            ->assertCreated();

        $this->assertTrue(
            $existing->tenants()->whereKey($tenantB->id)->wherePivot('status', 'active')->exists()
        );
    }

    public function test_attach_creates_user_if_not_exists(): void
    {
        $tenant = Tenant::create(['name' => 'NewCo', 'slug' => 'newco', 'status' => 'active']);
        $actor = $this->makeActorWithToken(['tenants.users.attach']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson("/api/tenants/{$tenant->id}/users", [
                'email' => 'nuevo@example.test',
                'name' => 'Nuevo',
                'password' => 'Secret123',
                'roles' => ['Vendedor'],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'nuevo@example.test']);
        $user = User::where('email', 'nuevo@example.test')->first();
        $this->assertTrue(
            $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists()
        );
    }

    public function test_attach_with_invalid_role_is_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'active']);
        $actor = $this->makeActorWithToken(['tenants.users.attach']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson("/api/tenants/{$tenant->id}/users", [
                'email' => 'no-role@example.test',
                'name' => 'NR',
                'roles' => ['RolQueNoExiste'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles']);
    }

    public function test_attach_requires_either_user_id_or_email(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'active']);
        $actor = $this->makeActorWithToken(['tenants.users.attach']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson("/api/tenants/{$tenant->id}/users", [
                'name' => 'Nada',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_attach_reactivates_inactive_user_in_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'active']);
        $user = User::factory()->create(['email' => 'reactivar@example.test']);
        $user->tenants()->attach($tenant, ['status' => 'inactive']);

        $actor = $this->makeActorWithToken(['tenants.users.attach']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson("/api/tenants/{$tenant->id}/users", [
                'user_id' => $user->id,
                'status' => 'active',
            ])
            ->assertCreated();

        $this->assertTrue(
            $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists()
        );
    }

    public function test_detach_user_removes_pivot_and_roles(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'active']);
        $user = User::factory()->create(['email' => 'detach@example.test']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        setPermissionsTeamId($tenant->id);
        $role = Role::create([
            'name' => 'Vendedor',
            'guard_name' => 'web',
            config('permission.column_names.team_foreign_key', 'team_id') => $tenant->id,
        ]);
        $user->assignRole($role);

        $actor = $this->makeActorWithToken(['tenants.users.detach']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->deleteJson("/api/tenants/{$tenant->id}/users/{$user->id}")
            ->assertNoContent();

        $this->assertFalse(
            $user->tenants()->whereKey($tenant->id)->exists(),
            'El pivot tenant_user debe haberse eliminado'
        );
    }

    public function test_detach_user_not_in_tenant_returns_422(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'active']);
        $user = User::factory()->create();
        $actor = $this->makeActorWithToken(['tenants.users.detach']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->deleteJson("/api/tenants/{$tenant->id}/users/{$user->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user']);
    }

    public function test_list_users_of_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'active']);
        $user1 = User::factory()->create(['name' => 'Ana', 'email' => 'ana@x.test']);
        $user2 = User::factory()->create(['name' => 'Beto', 'email' => 'beto@x.test']);
        $user1->tenants()->attach($tenant, ['status' => 'active']);
        $user2->tenants()->attach($tenant, ['status' => 'active']);

        $actor = $this->makeActorWithToken(['tenants.view']);

        $response = $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->getJson("/api/tenants/{$tenant->id}/users")
            ->assertOk()
            ->json();

        $this->assertSame(2, $response['meta']['total']);
        $this->assertSame(['Ana', 'Beto'], array_column($response['data'], 'name'));
    }

    public function test_user_without_attach_permission_cannot_attach(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'active']);
        $actor = $this->makeActorWithToken(['tenants.view']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson("/api/tenants/{$tenant->id}/users", [
                'email' => 'no-auth@example.test',
            ])
            ->assertForbidden();
    }

    private function makeActorWithToken(array $permissions): array
    {
        $hostTenant = Tenant::create(['name' => 'Host', 'slug' => 'host', 'status' => 'active']);

        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($hostTenant, ['status' => 'active']);

        setPermissionsTeamId($hostTenant->id);

        $role = Role::create([
            'name' => 'Actor-'.uniqid(),
            'guard_name' => 'web',
            config('permission.column_names.team_foreign_key', 'team_id') => $hostTenant->id,
        ]);
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $token = $this->withHeader('X-Tenant', $hostTenant->slug)
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'secret123',
                'device_name' => 'test',
            ])
            ->assertCreated()
            ->json('data.token');

        return ['user' => $user, 'host_tenant' => $hostTenant, 'token' => $token];
    }
}
