<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeviceNameValidationTest extends TestCase
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

    private function makeTenantAndUser(): array
    {
        $tenant = Tenant::create(['name' => 'Tienda Device', 'slug' => 'tienda-device']);
        $user = User::factory()->create(['email' => 'device@example.test', 'password' => 'Valid1234!']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        return [$tenant, $user];
    }

    public function test_clean_device_name_accepted_in_login(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser();

        $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'device@example.test',
                'password' => 'Valid1234!',
                'device_name' => 'Caja Principal Tienda',
            ])
            ->assertCreated();
    }

    public function test_device_name_with_control_characters_rejected_in_login(): void
    {
        [$tenant] = $this->makeTenantAndUser();

        $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'device@example.test',
                'password' => 'Valid1234!',
                'device_name' => "Caja\0Principal",
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_name']);
    }

    public function test_device_name_with_null_byte_rejected_in_login(): void
    {
        [$tenant] = $this->makeTenantAndUser();

        $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'device@example.test',
                'password' => 'Valid1234!',
                'device_name' => "Caja\x00Principal",
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_name']);
    }

    public function test_unicode_device_name_accepted(): void
    {
        [$tenant] = $this->makeTenantAndUser();

        $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'device@example.test',
                'password' => 'Valid1234!',
                'device_name' => 'Caja-Café-#1',
            ])
            ->assertCreated();
    }

    public function test_device_name_validation_works_for_switch_tenant_too(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser();

        $token = $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'device@example.test',
                'password' => 'Valid1234!',
            ])
            ->json('data.token');

        $tenant2 = Tenant::create(['name' => 'Tenant 2', 'slug' => 'tenant-2']);
        $user->tenants()->attach($tenant2, ['status' => 'active']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/switch-tenant', [
                'tenant_slug' => 'tenant-2',
                'device_name' => "Device\x01WithControl",
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_name']);
    }
}