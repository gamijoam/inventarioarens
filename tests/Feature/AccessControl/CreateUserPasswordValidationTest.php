<?php

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CreateUserPasswordValidationTest extends TestCase
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

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $role = Role::findOrCreate('Admin Create', 'web');
        $role->syncPermissions(['users.create']);
        $user->assignRole($role);

        return $user;
    }

    public function test_create_user_without_uppercase_returns_spanish_message(): void
    {
        $tenant = Tenant::create(['name' => 'PW Create', 'slug' => 'pw-create']);
        $admin = $this->admin($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users', [
                'name' => 'Test',
                'email' => 'test.pw@test.test',
                'password' => 'password123',
                'confirm_password' => 'password123',
                'roles' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users', [
                'name' => 'Test',
                'email' => 'test.pw@test.test',
                'password' => 'password123',
                'confirm_password' => 'password123',
                'roles' => [],
            ]);
        $this->assertStringContainsString(
            'mayuscula',
            $response->json('errors.password.0'),
            'El mensaje debe estar en espanol.'
        );
    }

    public function test_create_user_requires_confirm_password_when_password_set(): void
    {
        $tenant = Tenant::create(['name' => 'PW Create 2', 'slug' => 'pw-create-2']);
        $admin = $this->admin($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users', [
                'name' => 'Test',
                'email' => 'test2.pw@test.test',
                'password' => 'Passw0rd123',
                'roles' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirm_password']);
    }

    public function test_create_user_password_and_confirm_must_match(): void
    {
        $tenant = Tenant::create(['name' => 'PW Create 3', 'slug' => 'pw-create-3']);
        $admin = $this->admin($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users', [
                'name' => 'Test',
                'email' => 'test3.pw@test.test',
                'password' => 'Passw0rd123',
                'confirm_password' => 'Passw0rd999',
                'roles' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirm_password']);
    }

    public function test_create_user_without_password_still_works(): void
    {
        $tenant = Tenant::create(['name' => 'PW Create 4', 'slug' => 'pw-create-4']);
        $admin = $this->admin($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/users', [
                'name' => 'Test',
                'email' => 'test4.pw@test.test',
                'roles' => [],
            ])
            ->assertCreated();
    }
}
