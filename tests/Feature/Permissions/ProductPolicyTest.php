<?php

namespace Tests\Feature\Permissions;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_product_only_inside_current_tenant_with_permission(): void
    {
        [$tenantA, $tenantB] = $this->tenants();
        $user = $this->userInTenant($tenantA);

        $this->grantRole($tenantA, $user, 'Vendedor', ['products.view']);

        $productA = $this->productFor($tenantA, 'Redmi A3', 'REDMI-A3');
        $productB = $this->productFor($tenantB, 'Samsung A15', 'SAMSUNG-A15');

        $this->useTenant($tenantA);

        $this->assertTrue(Gate::forUser($user)->allows('view', $productA));
        $this->assertFalse(Gate::forUser($user)->allows('view', $productB));
    }

    public function test_same_user_role_does_not_leak_to_another_tenant(): void
    {
        [$tenantA, $tenantB] = $this->tenants();
        $user = $this->userInTenant($tenantA);
        $productB = $this->productFor($tenantB, 'Samsung A15', 'SAMSUNG-A15');

        $this->grantRole($tenantA, $user, 'Vendedor', ['products.view']);
        $this->useTenant($tenantB);

        $this->assertFalse(Gate::forUser($user)->allows('view', $productB));
    }

    public function test_create_requires_current_tenant_membership_and_permission(): void
    {
        [$tenantA, $tenantB] = $this->tenants();
        $user = $this->userInTenant($tenantA);

        $this->grantRole($tenantA, $user, 'Catalog Manager', ['products.create']);

        $this->useTenant($tenantA);
        $this->assertTrue(Gate::forUser($user)->allows('create', Product::class));

        $this->useTenant($tenantB);
        $this->assertFalse(Gate::forUser($user)->allows('create', Product::class));
    }

    public function test_update_and_delete_require_resource_to_belong_to_current_tenant(): void
    {
        [$tenantA, $tenantB] = $this->tenants();
        $user = $this->userInTenant($tenantA);

        $this->grantRole($tenantA, $user, 'Administrador', ['products.update', 'products.delete']);

        $productA = $this->productFor($tenantA, 'Redmi A3', 'REDMI-A3');
        $productB = $this->productFor($tenantB, 'Samsung A15', 'SAMSUNG-A15');

        $this->useTenant($tenantA);

        $this->assertTrue(Gate::forUser($user)->allows('update', $productA));
        $this->assertTrue(Gate::forUser($user)->allows('delete', $productA));
        $this->assertFalse(Gate::forUser($user)->allows('update', $productB));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $productB));
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function tenants(): array
    {
        return [
            Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']),
            Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']),
        ];
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function productFor(Tenant $tenant, string $name, string $sku): Product
    {
        $this->useTenant($tenant);

        return Product::create([
            'name' => $name,
            'sku' => $sku,
        ]);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
