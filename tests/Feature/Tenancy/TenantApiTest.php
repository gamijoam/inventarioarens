<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantApiTest extends TestCase
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

    public function test_unauthenticated_user_cannot_list_tenants(): void
    {
        $this->getJson('/api/tenants')->assertUnauthorized();
    }

    public function test_user_without_tenants_view_permission_is_forbidden(): void
    {
        $owner = $this->makeActorWithToken([], []);

        $this->withHeader('Authorization', 'Bearer '.$owner['token'])
            ->getJson('/api/tenants')
            ->assertForbidden();
    }

    public function test_owner_can_list_all_tenants(): void
    {
        Tenant::create(['name' => 'T1', 'slug' => 't1', 'status' => 'active']);
        Tenant::create(['name' => 'T2', 'slug' => 't2', 'status' => 'active']);

        $owner = $this->makeActorWithToken(['tenants.view'], []);

        $response = $this->withHeader('Authorization', 'Bearer '.$owner['token'])
            ->getJson('/api/tenants')
            ->assertOk()
            ->json();

        $slugs = array_column($response['data'], 'slug');
        $this->assertContains('t1', $slugs);
        $this->assertContains('t2', $slugs);
        $this->assertContains($owner['host_tenant']->slug, $slugs);
        $this->assertGreaterThanOrEqual(3, $response['meta']['total']);
    }

    public function test_show_returns_single_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Mostrar SA', 'slug' => 'mostrar-sa', 'plan' => 'demo', 'status' => 'active']);
        $owner = $this->makeActorWithToken(['tenants.view'], []);

        $this->withHeader('Authorization', 'Bearer '.$owner['token'])
            ->getJson("/api/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('data.slug', 'mostrar-sa')
            ->assertJsonPath('data.plan', 'demo');
    }

    public function test_update_changes_tenant_name_plan_and_status(): void
    {
        $tenant = Tenant::create(['name' => 'Antes', 'slug' => 'antes', 'status' => 'active']);
        $owner = $this->makeActorWithToken(['tenants.update'], []);

        $this->withHeader('Authorization', 'Bearer '.$owner['token'])
            ->patchJson("/api/tenants/{$tenant->id}", [
                'name' => 'Despues',
                'plan' => 'premium',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Despues')
            ->assertJsonPath('data.plan', 'premium');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Despues',
            'plan' => 'premium',
        ]);
    }

    public function test_destroy_deactivates_tenant_soft_delete(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'active']);
        $owner = $this->makeActorWithToken(['tenants.delete'], []);

        $this->withHeader('Authorization', 'Bearer '.$owner['token'])
            ->deleteJson("/api/tenants/{$tenant->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'inactive',
        ]);
    }

    public function test_destroy_twice_is_idempotent(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'inactive']);
        $owner = $this->makeActorWithToken(['tenants.delete'], []);

        $this->withHeader('Authorization', 'Bearer '.$owner['token'])
            ->deleteJson("/api/tenants/{$tenant->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'inactive',
        ]);
    }

    public function test_slug_validation_rejects_duplicates(): void
    {
        Tenant::create(['name' => 'A', 'slug' => 'repetido', 'status' => 'active']);
        $owner = $this->makeActorWithToken(['tenants.create'], []);

        $this->withHeader('Authorization', 'Bearer '.$owner['token'])
            ->postJson('/api/tenants', [
                'name' => 'Otro',
                'slug' => 'repetido',
                'admin' => ['name' => 'Admin', 'email' => 'a@b.test'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'Ya existe una empresa con ese slug.');
    }

    public function test_slug_validation_rejects_invalid_chars(): void
    {
        $owner = $this->makeActorWithToken(['tenants.create'], []);

        $this->withHeader('Authorization', 'Bearer '.$owner['token'])
            ->postJson('/api/tenants', [
                'name' => 'Test',
                'slug' => 'Tiene Mayusculas',
                'admin' => ['name' => 'A', 'email' => 'a@b.test'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    private function makeActorWithToken(array $permissions, array $additionalTenants = []): array
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