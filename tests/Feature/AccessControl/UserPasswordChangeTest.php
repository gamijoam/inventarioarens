<?php

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserPasswordChangeTest extends TestCase
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

    public function test_admin_can_change_other_user_password(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa PW', 'slug' => 'empresa-pw']);
        $admin = $this->userInTenant($tenant, 'admin@pw.test', 'password', true);
        $target = $this->userInTenant($tenant, 'target@pw.test', 'password');

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users/'.$target->id.'/password', [
                'new_password' => 'NuevaClave123',
                'confirm_password' => 'NuevaClave123',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'target@pw.test');

        $this->assertTrue(Hash::check('NuevaClave123', $target->fresh()->password));
        $this->assertFalse(Hash::check('password', $target->fresh()->password));
    }

    public function test_password_change_requires_confirmation_match(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa PW2', 'slug' => 'empresa-pw2']);
        $admin = $this->userInTenant($tenant, 'admin2@pw.test', 'password', true);
        $target = $this->userInTenant($tenant, 'target2@pw.test', 'password');

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users/'.$target->id.'/password', [
                'new_password' => 'NuevaClave123',
                'confirm_password' => 'OtraClave456',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirm_password']);
    }

    public function test_user_without_permission_cannot_change_password(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa PW3', 'slug' => 'empresa-pw3']);
        $member = $this->userInTenant($tenant, 'member@pw.test', 'password');
        $target = $this->userInTenant($tenant, 'target3@pw.test', 'password');

        $this
            ->actingAs($member)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users/'.$target->id.'/password', [
                'new_password' => 'NuevaClave123',
                'confirm_password' => 'NuevaClave123',
            ])
            ->assertForbidden();
    }

    public function test_admin_cannot_change_own_password(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa PW4', 'slug' => 'empresa-pw4']);
        $admin = $this->userInTenant($tenant, 'admin4@pw.test', 'password', true);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users/'.$admin->id.'/password', [
                'new_password' => 'NuevaClave123',
                'confirm_password' => 'NuevaClave123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_password']);
    }

    private function userInTenant(Tenant $tenant, string $email, string $password, bool $isAdmin = false): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => $password]);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->useTenant($tenant);

        if ($isAdmin) {
            $role = Role::findOrCreate('Admin PW', 'web');
            $role->syncPermissions(['users.view', 'users.update', 'users.create', 'users.delete']);
            $user->assignRole($role);
        }

        return $user;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
