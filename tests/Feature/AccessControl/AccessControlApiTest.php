<?php

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccessControlApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_tenant_user_and_assign_role(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $admin = $this->userInTenant($tenant);
        $this->grantRole($tenant, $admin, 'Access Admin', [
            'users.create',
            'users.view',
            'roles.view',
        ]);
        $this->grantRole($tenant, $admin, 'Vendedor', ['products.view']);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users', [
                'name' => 'Cajero Principal',
                'email' => 'cajero@example.test',
                'password' => 'Password123!',
                'roles' => ['Vendedor'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'cajero@example.test')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.roles.0.name', 'Vendedor');

        $created = User::where('email', 'cajero@example.test')->firstOrFail();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $created->id,
            'status' => 'active',
        ]);
    }

    public function test_current_administrator_is_listed_with_profiles_and_permissions_are_available(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $admin = $this->userInTenant($tenant);
        $this->grantRole($tenant, $admin, 'Administrador', BasePermissions::PERMISSIONS);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonFragment(['email' => $admin->email])
            ->assertJsonFragment(['name' => 'Administrador']);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/roles')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Administrador'])
            ->assertJsonFragment(['permissions' => collect(BasePermissions::PERMISSIONS)->sort()->values()->all()]);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonFragment(['module' => 'users'])
            ->assertJsonFragment(['module' => 'roles']);
    }

    public function test_users_and_roles_are_isolated_between_companies(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        $sharedUser = User::factory()->create(['email' => 'multiempresa@example.test']);
        $sharedUser->tenants()->attach($tenantA, ['status' => 'active']);
        $sharedUser->tenants()->attach($tenantB, ['status' => 'active']);

        $adminA = $this->userInTenant($tenantA);
        $adminB = $this->userInTenant($tenantB);

        $this->grantRole($tenantA, $adminA, 'Access Admin A', ['users.view', 'users.update', 'roles.view']);
        $this->grantRole($tenantB, $adminB, 'Access Admin B', ['users.view', 'users.update', 'roles.view']);
        $this->grantRole($tenantA, $sharedUser, 'Vendedor A', ['products.view']);
        $this->grantRole($tenantB, $sharedUser, 'Auditor B', ['reports.view']);

        $this
            ->actingAs($adminA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonFragment(['email' => 'multiempresa@example.test'])
            ->assertJsonFragment(['name' => 'Vendedor A'])
            ->assertJsonMissing(['name' => 'Auditor B']);

        $this
            ->actingAs($adminB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonFragment(['email' => 'multiempresa@example.test'])
            ->assertJsonFragment(['name' => 'Auditor B'])
            ->assertJsonMissing(['name' => 'Vendedor A']);
    }

    public function test_owner_can_list_users_across_group_and_spinoffs(): void
    {
        $group = Tenant::create(['name' => 'Grupo Danubio', 'slug' => 'danubio', 'is_group' => true]);
        $spinoff = Tenant::create([
            'name' => 'Danubio Empresa',
            'slug' => 'danubio-empresa',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);
        $owner = $this->userInTenant($group);
        $spinoffUser = $this->userInTenant($spinoff);

        $this->grantRole($group, $owner, 'Owner', ['users.view']);
        $this->grantRole($spinoff, $spinoffUser, 'Administrador', ['users.view']);

        $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonFragment(['email' => $owner->email])
            ->assertJsonMissing(['email' => $spinoffUser->email]);

        $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson('/api/users?scope=organization')
            ->assertOk()
            ->assertJsonFragment(['email' => $owner->email])
            ->assertJsonFragment(['email' => $spinoffUser->email])
            ->assertJsonFragment(['slug' => 'danubio-empresa']);
    }

    public function test_owner_can_open_user_detail_across_group_and_spinoffs(): void
    {
        $group = Tenant::create(['name' => 'Grupo Danubio', 'slug' => 'danubio', 'is_group' => true]);
        $spinoff = Tenant::create([
            'name' => 'Danubio Empresa',
            'slug' => 'danubio-empresa',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);
        $owner = $this->userInTenant($group);
        $spinoffUser = $this->userInTenant($spinoff);

        $this->grantRole($group, $owner, 'Owner', ['users.view']);
        $this->grantRole($spinoff, $spinoffUser, 'Administrador', ['users.view']);

        $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson("/api/users/{$spinoffUser->id}")
            ->assertNotFound();

        $this
            ->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->getJson("/api/users/{$spinoffUser->id}?scope=organization")
            ->assertOk()
            ->assertJsonPath('data.email', $spinoffUser->email)
            ->assertJsonFragment(['slug' => 'danubio-empresa']);
    }

    public function test_non_owner_cannot_list_organization_users_from_spinoff(): void
    {
        $group = Tenant::create(['name' => 'Grupo Danubio', 'slug' => 'danubio', 'is_group' => true]);
        $spinoff = Tenant::create([
            'name' => 'Danubio Empresa',
            'slug' => 'danubio-empresa',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);
        $admin = $this->userInTenant($spinoff);

        $this->grantRole($spinoff, $admin, 'Administrador', ['users.view']);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $spinoff->slug)
            ->getJson('/api/users?scope=organization')
            ->assertForbidden();
    }

    public function test_user_without_permission_cannot_manage_access(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'No Access', ['products.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/users')
            ->assertForbidden();
    }

    public function test_can_create_role_and_update_permissions(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $admin = $this->userInTenant($tenant);
        $this->grantRole($tenant, $admin, 'Role Admin', [
            'roles.create',
            'roles.update',
            'roles.view',
        ]);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/roles', [
                'name' => 'Supervisor POS',
                'permissions' => ['pos.view', 'pos.checkout'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Supervisor POS')
            ->assertJsonPath('data.permissions.0', 'pos.checkout')
            ->json('data');

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/roles/{$response['id']}/permissions", [
                'permissions' => ['pos.view', 'cash_register.view'],
            ])
            ->assertOk()
            ->assertJsonPath('data.permissions.0', 'cash_register.view')
            ->assertJsonPath('data.permissions.1', 'pos.view');
    }

    public function test_protected_base_role_cannot_be_deleted(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $admin = $this->userInTenant($tenant);
        $this->grantRole($tenant, $admin, 'Role Admin', ['roles.delete', 'roles.view']);
        $role = $this->grantRole($tenant, $admin, 'Administrador', BasePermissions::PERMISSIONS);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/roles/{$role->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_permissions_catalog_is_grouped_by_module(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $admin = $this->userInTenant($tenant);
        $this->grantRole($tenant, $admin, 'Access Admin', ['roles.view']);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonFragment(['module' => 'users'])
            ->assertJsonFragment(['module' => 'roles']);
    }

    public function test_can_inactivate_user_only_inside_current_company(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $adminA = $this->userInTenant($tenantA);
        $target = User::factory()->create();
        $target->tenants()->attach($tenantA, ['status' => 'active']);
        $target->tenants()->attach($tenantB, ['status' => 'active']);

        $this->grantRole($tenantA, $adminA, 'Access Admin', ['users.update', 'users.view']);

        $this
            ->actingAs($adminA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->patchJson("/api/users/{$target->id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenantA->id,
            'user_id' => $target->id,
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenantB->id,
            'user_id' => $target->id,
            'status' => 'active',
        ]);
    }

    public function test_access_changes_are_audited_with_actor_and_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $admin = $this->userInTenant($tenant);
        $target = $this->userInTenant($tenant);

        $this->grantRole($tenant, $admin, 'Access Admin', ['users.update', 'users.view', 'roles.view']);
        $this->grantRole($tenant, $target, 'Vendedor', ['products.view']);
        $this->grantRole($tenant, $target, 'Supervisor POS', ['pos.view']);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('User-Agent', 'AccessControlAuditTest/1.0')
            ->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->patchJson("/api/users/{$target->id}/roles", [
                'roles' => ['Supervisor POS'],
            ])
            ->assertOk();

        $this->useTenant($tenant);

        $audit = AuditLog::query()->firstOrFail();

        $this->assertSame('access.user.roles_updated', $audit->action);
        $this->assertSame(User::class, $audit->entity_type);
        $this->assertSame($target->id, $audit->entity_id);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame(['Supervisor POS', 'Vendedor'], $audit->old_values['roles']);
        $this->assertSame(['Supervisor POS'], $audit->new_values['roles']);
        $this->assertSame('10.20.30.40', $audit->ip_address);
        $this->assertSame('AccessControlAuditTest/1.0', $audit->user_agent);
    }

    public function test_cannot_inactivate_last_active_administrator(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $operator = $this->userInTenant($tenant);
        $admin = $this->userInTenant($tenant);

        $this->grantRole($tenant, $operator, 'Access Operator', ['users.update', 'users.view']);
        $this->grantRole($tenant, $admin, 'Administrador', BasePermissions::PERMISSIONS);

        $this
            ->actingAs($operator)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/users/{$admin->id}/status", ['status' => 'inactive'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);
    }

    public function test_cannot_remove_last_active_administrator_role(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $operator = $this->userInTenant($tenant);
        $admin = $this->userInTenant($tenant);

        $this->grantRole($tenant, $operator, 'Access Operator', ['users.update', 'users.view']);
        $this->grantRole($tenant, $admin, 'Administrador', BasePermissions::PERMISSIONS);
        $this->grantRole($tenant, $admin, 'Auditor', ['users.view']);

        $this
            ->actingAs($operator)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/users/{$admin->id}/roles", [
                'roles' => ['Auditor'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles']);

        $this->useTenant($tenant);
        $this->assertTrue($admin->hasRole('Administrador'));
    }

    public function test_roles_and_permissions_seeder_assigns_sales_returns_permissions_in_clean_database(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Seeder', 'slug' => 'empresa-seeder']);

        Permission::query()->delete();

        $this->seed(RolesAndPermissionsSeeder::class);

        setPermissionsTeamId($tenant->id);

        $this->assertDatabaseHas('permissions', [
            'name' => 'sales_returns.view',
            'guard_name' => 'web',
        ]);
        $this->assertTrue(Role::findByName('Gerente', 'web')->hasPermissionTo('sales_returns.view'));
        $this->assertTrue(Role::findByName('Vendedor', 'web')->hasPermissionTo('sales_returns.create'));
    }

    public function test_promote_admin_command_repairs_user_access_in_all_companies(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $manager = User::factory()->create(['email' => 'gerente@example.test']);
        $manager->tenants()->attach($tenantA, ['status' => 'active']);
        $manager->tenants()->attach($tenantB, ['status' => 'active']);

        $this->grantRole($tenantA, $manager, 'Gerente', ['users.view']);
        $this->grantRole($tenantB, $manager, 'Gerente', ['users.view']);

        $this
            ->artisan('access:promote-admin', ['email' => 'gerente@example.test'])
            ->assertExitCode(0);

        foreach ([$tenantA, $tenantB] as $tenant) {
            $this->useTenant($tenant);
            $this->assertTrue($manager->fresh()->hasRole('Administrador'));
            $this->assertTrue($manager->fresh()->can('roles.view'));
            $this->assertTrue($manager->fresh()->can('users.update'));

            $this
                ->actingAs($manager)
                ->withHeader('X-Tenant', $tenant->slug)
                ->getJson('/api/roles')
                ->assertOk();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): Role
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $role;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
