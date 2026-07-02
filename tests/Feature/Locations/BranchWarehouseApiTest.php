<?php

namespace Tests\Feature\Locations;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BranchWarehouseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_update_and_deactivate_branch(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Location Manager', [
            'branches.create',
            'branches.update',
            'branches.delete',
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/branches', [
                'name' => 'Principal',
                'code' => 'MAIN',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Principal')
            ->assertJsonPath('data.code', 'MAIN')
            ->assertJsonPath('data.status', Branch::STATUS_ACTIVE);

        $branchId = $response->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/branches/{$branchId}", [
                'name' => 'Principal Caracas',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Principal Caracas');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/branches/{$branchId}")
            ->assertNoContent();

        $this->assertDatabaseHas('branches', [
            'id' => $branchId,
            'tenant_id' => $tenant->id,
            'status' => Branch::STATUS_INACTIVE,
        ]);
    }

    public function test_branches_index_does_not_mix_multiple_companies_and_code_is_unique_per_tenant(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        $this->branchFor($tenantA, 'Principal A', 'MAIN');
        $this->branchFor($tenantB, 'Principal B', 'MAIN');

        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Location Manager', ['branches.view', 'branches.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Principal A');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/branches', [
                'name' => 'Otra principal',
                'code' => 'MAIN',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_user_can_create_update_and_deactivate_warehouse_inside_current_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branchFor($tenant, 'Principal', 'MAIN');
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Location Manager', [
            'warehouses.create',
            'warehouses.update',
            'warehouses.delete',
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warehouses', [
                'branch_id' => $branch->id,
                'name' => 'Almacen tienda',
                'code' => 'WH-STORE',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Almacen tienda')
            ->assertJsonPath('data.code', 'WH-STORE')
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.status', Warehouse::STATUS_ACTIVE);

        $warehouseId = $response->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/warehouses/{$warehouseId}", [
                'name' => 'Almacen principal',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Almacen principal');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/warehouses/{$warehouseId}")
            ->assertNoContent();

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouseId,
            'tenant_id' => $tenant->id,
            'status' => Warehouse::STATUS_INACTIVE,
        ]);
    }

    public function test_warehouse_api_rejects_branch_from_another_tenant_and_duplicate_code_inside_tenant(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        $branchA = $this->branchFor($tenantA, 'Principal A', 'MAIN');
        $branchB = $this->branchFor($tenantB, 'Principal B', 'MAIN');
        $this->warehouseFor($tenantA, $branchA, 'Almacen A', 'WH');

        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Location Manager', ['warehouses.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/warehouses', [
                'branch_id' => $branchB->id,
                'name' => 'Almacen invalido',
                'code' => 'WH-OTHER',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/warehouses', [
                'branch_id' => $branchA->id,
                'name' => 'Almacen duplicado',
                'code' => 'WH',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_location_apis_reject_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/branches', [
                'name' => 'Principal',
                'code' => 'MAIN',
            ])
            ->assertForbidden();

        $branch = $this->branchFor($tenant, 'Principal', 'MAIN');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warehouses', [
                'branch_id' => $branch->id,
                'name' => 'Almacen',
                'code' => 'WH',
            ])
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

    private function branchFor(Tenant $tenant, string $name, string $code): Branch
    {
        $this->useTenant($tenant);

        return Branch::create([
            'name' => $name,
            'code' => $code,
        ]);
    }

    private function warehouseFor(Tenant $tenant, Branch $branch, string $name, string $code): Warehouse
    {
        $this->useTenant($tenant);

        return Warehouse::create([
            'branch_id' => $branch->id,
            'name' => $name,
            'code' => $code,
        ]);
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

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
