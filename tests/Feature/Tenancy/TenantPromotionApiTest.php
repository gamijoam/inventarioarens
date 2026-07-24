<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Verifica que un usuario autenticado puede promover su empresa
 * (tenant root sin hijos) a grupo multi-empresa. La accion:
 *  - marca is_group=true y parent_id=null,
 *  - asigna el rol Owner al actor,
 *  - queda visible para /api/tenant-groups como Owner.
 */
class TenantPromotionApiTest extends TestCase
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

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }

    private function makeMemberWithAdminRole(Tenant $tenant, string $email): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => $email,
            'password' => bcrypt('secret123'),
        ]);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        setPermissionsTeamId($tenant->id);
        $role = Role::create([
            'name' => 'Administrador',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $role->syncPermissions(
            Permission::query()->whereIn('name', BasePermissions::PERMISSIONS)->get(),
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($tenant->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    public function test_member_can_promote_own_tenant_to_group(): void
    {
        $tenant = Tenant::create([
            'name' => 'Mi Empresa',
            'slug' => 'mi-empresa',
            'is_group' => false,
        ]);
        $admin = $this->makeMemberWithAdminRole($tenant, 'admin@promo.test');

        $response = $this
            ->actingAs($admin)
            ->postJson("/api/tenants/{$tenant->id}/promote-to-group");

        $response->assertOk()
            ->assertJsonPath('data.is_group', true)
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.slug', 'mi-empresa');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'is_group' => true,
            'parent_id' => null,
        ]);

        $spinoffId = Tenant::where('slug', 'mi-empresa')->value('id');
        $this->assertDatabaseHas('roles', [
            'name' => 'Owner',
            'tenant_id' => $spinoffId,
        ]);
    }

    public function test_non_member_cannot_promote_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Otra Empresa',
            'slug' => 'otra-empresa',
            'is_group' => false,
        ]);
        $stranger = User::create([
            'name' => 'Intruso',
            'email' => 'intruso@promo.test',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this
            ->actingAs($stranger)
            ->postJson("/api/tenants/{$tenant->id}/promote-to-group");

        $response->assertStatus(422);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'is_group' => false,
        ]);
    }

    public function test_cannot_promote_tenant_with_existing_children(): void
    {
        $tenant = Tenant::create([
            'name' => 'Con Hijos',
            'slug' => 'con-hijos',
            'is_group' => false,
        ]);
        Tenant::create([
            'name' => 'Hijo',
            'slug' => 'hijo',
            'is_group' => false,
            'parent_id' => $tenant->id,
        ]);
        $admin = $this->makeMemberWithAdminRole($tenant, 'admin@conhijos.test');

        $response = $this
            ->actingAs($admin)
            ->postJson("/api/tenants/{$tenant->id}/promote-to-group");

        $response->assertStatus(422);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'is_group' => false,
        ]);
    }

    public function test_cannot_promote_a_spinoff(): void
    {
        $group = Tenant::create(['name' => 'Grupo', 'slug' => 'grupo-promote', 'is_group' => true]);
        $spinoff = Tenant::create([
            'name' => 'Sucursal',
            'slug' => 'sucursal',
            'is_group' => false,
            'parent_id' => $group->id,
        ]);
        $admin = $this->makeMemberWithAdminRole($spinoff, 'admin@spinoff.test');

        $response = $this
            ->actingAs($admin)
            ->postJson("/api/tenants/{$spinoff->id}/promote-to-group");

        $response->assertStatus(422);
        $this->assertDatabaseHas('tenants', [
            'id' => $spinoff->id,
            'is_group' => false,
            'parent_id' => $group->id,
        ]);
    }
}
