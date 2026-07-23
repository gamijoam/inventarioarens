<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantGroupApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crea un grupo y le asigna al user el rol "Owner" + attach activo.
     * Devuelve [$user, $token, $group].
     *
     * @return array{0: User, 1: string, 2: Tenant}
     */
    private function makeGroupWithOwner(string $name = 'G', string $slug = 'g'): array
    {
        [$user, $token] = $this->makeUserWithToken();

        $group = Tenant::create(['name' => $name, 'slug' => $slug, 'is_group' => true]);
        $user->tenants()->attach($group, ['status' => 'active']);

        $role = Role::create(['name' => 'Owner', 'guard_name' => 'web']);
        $role->tenant_id = $group->id;
        $role->save();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($group->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $token, $group];
    }

    public function test_index_returns_groups_where_user_is_owner(): void
    {
        [$user, $token, $myGroup] = $this->makeGroupWithOwner('Mi Holding', 'mi-holding');

        // Grupo donde el user NO es miembro
        Tenant::create(['name' => 'Otro Grupo', 'slug' => 'otro-grupo', 'is_group' => true]);

        // Spinoff del grupo: NO debe aparecer aunque sea miembro
        $spinoff = Tenant::create([
            'name' => 'Empresa Spinoff',
            'slug' => 'spinoff',
            'is_group' => false,
            'parent_id' => $myGroup->id,
        ]);
        $user->tenants()->attach($spinoff, ['status' => 'active']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tenant-groups');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $myGroup->id)
            ->assertJsonPath('data.0.slug', 'mi-holding')
            ->assertJsonCount(1, 'data');
    }

    public function test_index_excludes_groups_where_user_is_only_member_without_owner_role(): void
    {
        // user es miembro activo del grupo pero NO tiene rol 'Owner'.
        [$user, $token] = $this->makeUserWithToken();

        $group = Tenant::create(['name' => 'G', 'slug' => 'g', 'is_group' => true]);
        $user->tenants()->attach($group, ['status' => 'active']);

        // Asignar rol Vendedor (no Owner) en el grupo.
        $role = Role::create(['name' => 'Vendedor', 'guard_name' => 'web']);
        $role->tenant_id = $group->id;
        $role->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($group->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tenant-groups');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_index_excludes_groups_with_inactive_membership(): void
    {
        [$user, $token, $group] = $this->makeGroupWithOwner();

        // Forzar attach inactivo (helper ya lo hizo activo).
        $user->tenants()->updateExistingPivot($group->id, ['status' => 'inactive']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tenant-groups');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_index_includes_children_count_and_users_count(): void
    {
        [$user, $token, $group] = $this->makeGroupWithOwner();

        // Spinoff hijo
        Tenant::create([
            'name' => 'Spinoff',
            'slug' => 's',
            'is_group' => false,
            'parent_id' => $group->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tenant-groups');

        $response->assertOk()
            ->assertJsonPath('data.0.children_count', 1)
            ->assertJsonPath('data.0.users_count', 1);
    }

    public function test_spinoffs_endpoint_returns_only_spinoffs_of_group(): void
    {
        [$user, $token, $group] = $this->makeGroupWithOwner();

        Tenant::create([
            'name' => 'Sp1', 'slug' => 'sp1', 'is_group' => false, 'parent_id' => $group->id,
        ]);
        Tenant::create([
            'name' => 'Sp2', 'slug' => 'sp2', 'is_group' => false, 'parent_id' => $group->id,
        ]);
        // Grupo de otro parent: no debe aparecer
        $otherGroup = Tenant::create(['name' => 'OG', 'slug' => 'og', 'is_group' => true]);
        Tenant::create([
            'name' => 'Other', 'slug' => 'other', 'is_group' => false, 'parent_id' => $otherGroup->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tenant-groups/'.$group->id.'/spinoffs');

        $response->assertOk()->assertJsonCount(2, 'data');

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('sp1', $slugs);
        $this->assertContains('sp2', $slugs);
        $this->assertNotContains('other', $slugs);
    }

    public function test_spinoffs_endpoint_allows_any_active_member(): void
    {
        // user es miembro activo del grupo con un rol NO-Owner (p.ej. Vendedor).
        // Puede ver los spinoffs porque es miembro activo.
        [$user, $token] = $this->makeUserWithToken();

        $group = Tenant::create(['name' => 'G', 'slug' => 'g', 'is_group' => true]);
        $user->tenants()->attach($group, ['status' => 'active']);

        $role = Role::create(['name' => 'Vendedor', 'guard_name' => 'web']);
        $role->tenant_id = $group->id;
        $role->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($group->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Tenant::create([
            'name' => 'Sp', 'slug' => 'sp', 'is_group' => false, 'parent_id' => $group->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tenant-groups/'.$group->id.'/spinoffs');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_spinoffs_endpoint_requires_user_to_be_member_of_group(): void
    {
        // user NO es miembro del grupo: 403.
        [$user, $token] = $this->makeUserWithToken();

        $group = Tenant::create(['name' => 'G', 'slug' => 'g', 'is_group' => true]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tenant-groups/'.$group->id.'/spinoffs');

        $response->assertForbidden();
    }

    public function test_create_spinoff_for_group_works(): void
    {
        [$user, $token, $group] = $this->makeGroupWithOwner('Mi Holding', 'mi-holding');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/tenant-groups/'.$group->id.'/tenants', [
                'name' => 'Empresa Nueva',
                'slug' => 'empresa-nueva',
                'admin' => [
                    'name' => 'Admin Nueva',
                    'email' => 'admin@nueva.test',
                    'password' => 'Secret1234',
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'empresa-nueva')
            ->assertJsonPath('data.parent_id', $group->id)
            ->assertJsonPath('data.is_group', false);

        $this->assertDatabaseHas('tenants', [
            'slug' => 'empresa-nueva',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);
    }

    public function test_create_spinoff_seeds_base_roles_for_the_new_company(): void
    {
        [$user, $token, $group] = $this->makeGroupWithOwner('Mi Holding', 'mi-holding');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/tenant-groups/'.$group->id.'/tenants', [
                'name' => 'Empresa Nueva',
                'slug' => 'empresa-nueva',
                'admin' => [
                    'name' => 'Admin Nueva',
                    'email' => 'admin@nueva.test',
                    'password' => 'Secret1234',
                ],
            ])
            ->assertCreated();

        $admin = User::where('email', 'admin@nueva.test')->firstOrFail();

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', 'empresa-nueva')
            ->getJson('/api/roles')
            ->assertOk()
            ->assertJsonCount(6, 'data');

        $spinoffId = Tenant::where('slug', 'empresa-nueva')->value('id');

        $this->assertDatabaseHas('roles', ['name' => 'Owner', 'tenant_id' => $spinoffId]);
        $this->assertDatabaseHas('roles', ['name' => 'Administrador', 'tenant_id' => $spinoffId]);
        $this->assertDatabaseHas('roles', ['name' => 'Gerente', 'tenant_id' => $spinoffId]);
        $this->assertDatabaseHas('roles', ['name' => 'Vendedor', 'tenant_id' => $spinoffId]);
        $this->assertDatabaseHas('roles', ['name' => 'Almacen', 'tenant_id' => $spinoffId]);
        $this->assertDatabaseHas('roles', ['name' => 'Auditor', 'tenant_id' => $spinoffId]);
    }

    public function test_create_spinoff_ignores_empty_optional_sections(): void
    {
        [$user, $token, $group] = $this->makeGroupWithOwner('Mi Holding', 'mi-holding');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/tenant-groups/'.$group->id.'/tenants', [
                'name' => 'Empresa Vacía',
                'slug' => 'empresa-vacia',
                'admin' => [
                    'name' => 'Admin Vacía',
                    'email' => 'admin@vacia.test',
                    'password' => 'Secret1234',
                ],
                'branch' => [],
                'warehouse' => [],
                'exchange_rate_type' => [],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'empresa-vacia');
    }

    public function test_create_spinoff_attaches_creator_to_available_tenants(): void
    {
        [$user, $token, $group] = $this->makeGroupWithOwner('Mi Holding', 'mi-holding');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/tenant-groups/'.$group->id.'/tenants', [
                'name' => 'Empresa Nueva',
                'slug' => 'empresa-nueva',
                'admin' => [
                    'name' => 'Admin Nueva',
                    'email' => 'admin@nueva.test',
                    'password' => 'Secret1234',
                ],
            ])
            ->assertCreated();

        $spinoff = Tenant::where('slug', 'empresa-nueva')->firstOrFail();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $spinoff->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $adminRoleId = Role::query()
            ->where('name', 'Administrador')
            ->where('tenant_id', $spinoff->id)
            ->value('id');

        $this->assertNotNull($adminRoleId);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $adminRoleId,
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);

        $response = $this->postJson('/api/auth/tenants', ['email' => $user->email]);

        $response->assertOk()->assertJsonCount(2, 'data');

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('mi-holding', $slugs);
        $this->assertContains('empresa-nueva', $slugs);
    }

    public function test_users_endpoint_returns_normalized_users_from_group_and_spinoffs(): void
    {
        [$owner, $token, $group] = $this->makeGroupWithOwner('Danubio', 'danubio');

        $spinoff = Tenant::create([
            'name' => 'Danubio',
            'slug' => 'danubio-empresa',
            'is_group' => false,
            'parent_id' => $group->id,
        ]);

        $user = User::factory()->create([
            'name' => 'Usuario Danubio',
            'email' => 'usuario@danubio.test',
        ]);
        $user->tenants()->attach($spinoff, ['status' => 'active']);

        $role = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        $role->tenant_id = $spinoff->id;
        $role->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($spinoff->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tenant-groups/'.$group->id.'/users');

        $response->assertOk();

        $listedUser = collect($response->json('data.data'))
            ->firstWhere('email', 'usuario@danubio.test');

        $this->assertSame('Usuario Danubio', $listedUser['name']);
        $this->assertSame('active', $listedUser['status']);
        $this->assertSame('Administrador', $listedUser['roles'][0]['name']);
        $this->assertSame('danubio-empresa', $listedUser['tenants'][0]['slug']);
        $this->assertSame('active', $listedUser['tenants'][0]['status']);

        $this->assertTrue($owner->isOwnerOf($group));
    }

    public function test_attach_user_with_missing_user_id_returns_validation_error_instead_of_500(): void
    {
        [$user, $token, $group] = $this->makeGroupWithOwner('Mi Holding', 'mi-holding');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/tenant-groups/'.$group->id.'/users', [
                'user_id' => 999999,
                'name' => 'Usuario Fantasma',
                'email' => 'fantasma@test.test',
                'roles' => ['Vendedor'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function makeUserWithToken(): array
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $token = Str::random(80);
        AuthToken::create([
            'tenant_id' => null,
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $token];
    }
}
