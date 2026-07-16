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

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
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

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/tenant-groups');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_index_excludes_groups_with_inactive_membership(): void
    {
        [$user, $token, $group] = $this->makeGroupWithOwner();

        // Forzar attach inactivo (helper ya lo hizo activo).
        $user->tenants()->updateExistingPivot($group->id, ['status' => 'inactive']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
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

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
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

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/tenant-groups/' . $group->id . '/spinoffs');

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

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/tenant-groups/' . $group->id . '/spinoffs');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_spinoffs_endpoint_requires_user_to_be_member_of_group(): void
    {
        // user NO es miembro del grupo: 403.
        [$user, $token] = $this->makeUserWithToken();

        $group = Tenant::create(['name' => 'G', 'slug' => 'g', 'is_group' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/tenant-groups/' . $group->id . '/spinoffs');

        $response->assertForbidden();
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