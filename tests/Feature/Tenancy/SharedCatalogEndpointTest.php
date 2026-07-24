<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SharedCatalogEndpointTest extends TestCase
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

    public function test_owner_can_list_group_shared_catalog_with_master_and_copies(): void
    {
        [$group, $spinoff, $owner] = $this->setupGroupWithOwnerAndSpinoff();

        $this->useTenant($group);

        $master = Product::query()->withoutGlobalScopes()->create([
            'tenant_id' => $group->id,
            'name' => 'iPhone 15',
            'sku' => 'IPHONE15',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 700,
            'sale_currency' => Product::CURRENCY_USD,
            'is_catalog_master' => true,
        ]);

        $this->assertSame($group->id, $master->tenant_id);

        $copy = Product::query()->withoutGlobalScopes()->create([
            'tenant_id' => $spinoff->id,
            'name' => 'iPhone 15',
            'sku' => 'IPHONE15',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 700,
            'sale_currency' => Product::CURRENCY_USD,
            'catalog_product_id' => $master->id,
            'is_catalog_master' => false,
        ]);

        $this->assertSame($spinoff->id, $copy->tenant_id);

        $this->actingAs($owner)
            ->getJson("/api/tenant-groups/{$group->id}/shared-products")
            ->assertOk();

        $response = $this
            ->actingAs($owner)
            ->getJson("/api/tenant-groups/{$group->id}/shared-products")
            ->assertOk();

        $response
            ->assertJsonPath('data.group.id', $group->id)
            ->assertJsonPath('data.spinoffs.0.id', $spinoff->id)
            ->assertJsonPath('data.products.0.master.id', $master->id)
            ->assertJsonPath('data.products.0.master.is_catalog_master', true)
            ->assertJsonPath('data.products.0.copies.0.spinoff_id', $spinoff->id)
            ->assertJsonPath('data.products.0.copies.0.product_id', $copy->id)
            ->assertJsonPath('data.products.0.copies.0.propagated', true)
            ->assertJsonPath('data.products.0.copies.0.is_active', true);
    }

    public function test_non_member_cannot_access_shared_catalog(): void
    {
        [$group, $spinoff, $owner] = $this->setupGroupWithOwnerAndSpinoff();
        $this->useTenant($group);

        $outsider = User::create([
            'name' => 'Outsider',
            'email' => 'outsider.shared@test.test',
            'password' => bcrypt('secret123'),
        ]);

        $this->actingAs($outsider)
            ->getJson("/api/tenant-groups/{$group->id}/shared-products")
            ->assertForbidden();
    }

    public function test_endpoint_reports_pending_copies_for_missing_spinoff_propagation(): void
    {
        [$group, $spinoff, $owner] = $this->setupGroupWithOwnerAndSpinoff();
        $this->useTenant($group);

        Product::query()->withoutGlobalScopes()->create([
            'tenant_id' => $group->id,
            'name' => 'Pixel 9',
            'sku' => 'PIXEL9',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'base_price' => 600,
            'sale_currency' => Product::CURRENCY_USD,
            'is_catalog_master' => true,
        ]);

        $this->actingAs($owner)
            ->getJson("/api/tenant-groups/{$group->id}/shared-products")
            ->assertOk()
            ->assertJsonPath('data.products.0.copies.0.spinoff_id', $spinoff->id)
            ->assertJsonPath('data.products.0.copies.0.propagated', false)
            ->assertJsonPath('data.products.0.copies.0.product_id', null);
    }

    /**
     * @return array{0: Tenant, 1: Tenant, 2: User}
     */
    private function setupGroupWithOwnerAndSpinoff(): array
    {
        $group = Tenant::create([
            'name' => 'Demo Group',
            'slug' => 'demo-shared-catalog',
            'is_group' => true,
        ]);

        $spinoff = Tenant::create([
            'name' => 'Demo Spinoff',
            'slug' => 'demo-shared-spinoff',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);

        // El TenantManager debe estar seteado ANTES de crear el Role porque
        // AccessControlService::syncRolesPermissions lo requiere. Si no,
        // "No current tenant has been resolved for this operation".
        app(TenantManager::class)->set($group);
        setPermissionsTeamId($group->id);

        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner.shared@test.test',
            'password' => bcrypt('secret123'),
        ]);
        $owner->tenants()->attach($group, ['status' => 'active']);

        $role = Role::create([
            'name' => 'Owner',
            'guard_name' => 'web',
            'tenant_id' => $group->id,
        ]);
        $role->syncPermissions(
            Permission::query()->whereIn('name', BasePermissions::PERMISSIONS)->get(),
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($group->id);
        $owner->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$group, $spinoff, $owner];
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
