<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantSpinoffService;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GroupOwnerPropagationTest extends TestCase
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

    public function test_new_owner_gets_attached_to_all_group_spinoffs(): void
    {
        $group = Tenant::create(['name' => 'Grupo', 'slug' => 'grupo', 'is_group' => true, 'parent_id' => null]);
        $spinoff = Tenant::create(['name' => 'Hija', 'slug' => 'hija', 'is_group' => false, 'parent_id' => $group->id]);
        $this->useTenant($group);
        $this->seedBaseRoles($group);
        $this->seedBaseRoles($spinoff);

        $admin = User::factory()->create();
        $admin->tenants()->attach($group, ['status' => 'active']);
        $role = Role::findOrCreate('Owner', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $admin->assignRole($role);
        $this->grantPermission($admin, 'users.create');

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $group->slug)
            ->postJson('/api/users', [
                'name' => 'Nuevo Owner',
                'email' => 'nuevo.owner@test.test',
                'password' => 'NuevaClave123',
                'confirm_password' => 'NuevaClave123',
                'roles' => ['Owner'],
            ])
            ->assertCreated();

        $newOwner = User::query()->where('email', 'nuevo.owner@test.test')->firstOrFail();

        // Activo en el grupo y en la hija.
        $this->assertTrue($this->isActiveMember($newOwner, $group));
        $this->assertTrue($this->isActiveMember($newOwner, $spinoff));

        // Rol Owner en el grupo.
        $this->useTenant($group);
        $this->assertContains('Owner', $newOwner->roles()->pluck('name')->all());

        // Rol Administrador en la hija.
        $this->useTenant($spinoff);
        $this->assertContains('Administrador', $newOwner->roles()->pluck('name')->all());

        // availableTenants (login) retorna grupo + hija.
        $tenants = $this
            ->postJson('/api/auth/tenants', ['email' => 'nuevo.owner@test.test'])
            ->assertOk()
            ->json('data');
        $slugs = collect($tenants)->pluck('slug')->sort()->values()->all();
        $this->assertSame(['grupo', 'hija'], $slugs);
    }

    public function test_new_spinoff_attaches_all_group_owners(): void
    {
        $group = Tenant::create(['name' => 'Grupo2', 'slug' => 'grupo2', 'is_group' => true, 'parent_id' => null]);
        $this->useTenant($group);
        $this->seedBaseRoles($group);

        $ownerA = User::factory()->create();
        $ownerA->tenants()->attach($group, ['status' => 'active']);
        $roleA = Role::findOrCreate('Owner', 'web');
        $roleA->syncPermissions(BasePermissions::PERMISSIONS);
        $ownerA->assignRole($roleA);

        // Owner B: se crea via API (debe propagarse a la hija al crearse).
        $admin = $ownerA;
        $this->grantPermission($admin, 'users.create');
        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $group->slug)
            ->postJson('/api/users', [
                'name' => 'Owner B',
                'email' => 'owner.b@test.test',
                'password' => 'NuevaClave123',
                'confirm_password' => 'NuevaClave123',
                'roles' => ['Owner'],
            ])
            ->assertCreated();
        $ownerB = User::query()->where('email', 'owner.b@test.test')->firstOrFail();

        // Se crea una hija nueva.
        app(TenantSpinoffService::class)->createSpinoff($group, [
            'slug' => 'hija2',
            'name' => 'Hija 2',
            'admin' => ['email' => 'admin.hija2@test.test', 'name' => 'Admin Hija2'],
        ], $ownerA);

        $spinoff = Tenant::query()->where('slug', 'hija2')->firstOrFail();

        $this->assertTrue($this->isActiveMember($ownerA, $spinoff));
        $this->assertTrue($this->isActiveMember($ownerB, $spinoff));
    }

    private function isActiveMember(User $user, Tenant $tenant): bool
    {
        return DB::table('tenant_user')
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->exists();
    }

    private function seedBaseRoles(Tenant $tenant): void
    {
        app(TenantSpinoffService::class)->seedBaseRoles($tenant);
    }

    private function grantPermission(User $user, string $permission): void
    {
        $user->givePermissionTo($permission);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
