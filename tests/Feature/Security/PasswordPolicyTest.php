<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\AccessControl\Models\Role;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
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

    private function makeTenantAndAdmin(Tenant $tenant): \App\Models\User
    {
        $admin = User::factory()->create();
        $admin->tenants()->attach($tenant, ['status' => 'active']);

        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $role = SpatieRole::findOrCreate('Admin Users', 'web');
        $role->syncPermissions(['users.create', 'users.update', 'users.view']);
        $admin->assignRole($role);

        return $admin;
    }

    public function test_strong_password_accepted(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Pwd', 'slug' => 'tienda-pwd']);
        $admin = $this->makeTenantAndAdmin($tenant);

        $this->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users', [
                'name' => 'Usuario Valido',
                'email' => 'valido@example.test',
                'password' => 'Valid1234!',
                'roles' => [],
            ])
            ->assertCreated();
    }

    public function test_short_password_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Pwd 2', 'slug' => 'tienda-pwd-2']);
        $admin = $this->makeTenantAndAdmin($tenant);

        $this->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users', [
                'name' => 'Usuario Corto',
                'email' => 'corto@example.test',
                'password' => 'Ab1!',
                'roles' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_password_without_uppercase_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Pwd 3', 'slug' => 'tienda-pwd-3']);
        $admin = $this->makeTenantAndAdmin($tenant);

        $this->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users', [
                'name' => 'Usuario Minus',
                'email' => 'minus@example.test',
                'password' => 'alllowercase1!',
                'roles' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_password_without_numbers_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Pwd 4', 'slug' => 'tienda-pwd-4']);
        $admin = $this->makeTenantAndAdmin($tenant);

        $this->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users', [
                'name' => 'Usuario SinNumero',
                'email' => 'sinnumero@example.test',
                'password' => 'OnlyLetters!!',
                'roles' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_password_field_is_optional(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Pwd 5', 'slug' => 'tienda-pwd-5']);
        $admin = $this->makeTenantAndAdmin($tenant);

        $this->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users', [
                'name' => 'Usuario Sin Pwd',
                'email' => 'sinpwd@example.test',
                'roles' => [],
            ])
            ->assertCreated();
    }
}