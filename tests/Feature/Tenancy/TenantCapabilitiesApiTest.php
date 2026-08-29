<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantCapabilityService;
use App\Support\Capabilities\BaseCapabilities;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantCapabilitiesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tenant_profile_enables_only_core_and_inventory(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Nueva', 'slug' => 'empresa-nueva']);
        $service = app(TenantCapabilityService::class);

        $service->initializeForNewTenant($tenant);

        $this->assertSame(BaseCapabilities::DEFAULT_NEW, $service->enabledKeys($tenant));
        $this->assertTrue($service->enabled($tenant, 'inventory'));
        $this->assertFalse($service->enabled($tenant, 'pos'));
    }

    public function test_legacy_tenant_without_capability_rows_keeps_all_capabilities(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Legacy', 'slug' => 'empresa-legacy']);

        $this->assertSame(BaseCapabilities::ALL, app(TenantCapabilityService::class)->enabledKeys($tenant));
    }

    public function test_authenticated_user_can_read_current_tenant_capabilities(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Catalog Viewer', ['products.view']);
        app(TenantCapabilityService::class)->initializeForNewTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/tenant-capabilities')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.capabilities.0.key', 'dashboard')
            ->assertJsonPath('data.capabilities.0.enabled', true)
            ->assertJsonPath('data.capabilities.5.key', 'sales')
            ->assertJsonPath('data.capabilities.5.enabled', false);
    }

    public function test_manager_can_enable_optional_capability_without_disabling_required_ones(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $admin = $this->userInTenant($tenant);
        $this->grantRole($tenant, $admin, 'Settings Manager', ['settings.manage']);
        app(TenantCapabilityService::class)->initializeForNewTenant($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/tenant-capabilities', [
                'capabilities' => ['pos'],
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled.0', 'dashboard')
            ->assertJsonFragment(['key' => 'pos', 'enabled' => true]);

        $service = app(TenantCapabilityService::class);
        $this->assertTrue($service->enabled($tenant, 'dashboard'));
        $this->assertTrue($service->enabled($tenant, 'inventory'));
        $this->assertTrue($service->enabled($tenant, 'pos'));
    }

    public function test_user_without_settings_permission_cannot_change_capabilities(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Catalog Viewer', ['products.view']);
        app(TenantCapabilityService::class)->initializeForNewTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/tenant-capabilities', ['capabilities' => ['pos']])
            ->assertForbidden();
    }

    public function test_disabled_pos_capability_blocks_pos_bootstrap(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa POS', 'slug' => 'empresa-pos']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'POS User', ['pos.view']);
        app(TenantCapabilityService::class)->initializeForNewTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/pos/bootstrap')
            ->assertForbidden();
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

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }
}
