<?php

namespace Tests\Feature\Warehouses;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Coverage del flag `is_default` en almacenes:
 *  - Crear un almacen con is_default=true limpia los demas.
 *  - Actualizar un almacen con is_default=true hace swap.
 *  - El index ordena el predeterminado primero y expone is_default.
 *  - Aislamiento cross-tenant: el default de A no afecta a B.
 */
class WarehouseDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_with_is_default_true_clears_other_defaults(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $branchId = $this->branchId($tenant);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warehouses', [
                'branch_id' => $branchId,
                'name' => 'Almacen A',
                'code' => 'AA',
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warehouses', [
                'branch_id' => $branchId,
                'name' => 'Almacen B',
                'code' => 'BB',
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);

        $this->assertSame(
            1,
            Warehouse::query()->where('tenant_id', $tenant->id)->where('is_default', true)->count(),
        );
        $this->assertSame('BB', Warehouse::query()->where('tenant_id', $tenant->id)->where('is_default', true)->value('code'));
    }

    public function test_update_with_is_default_true_swaps(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $branchId = $this->branchId($tenant);

        $a = $this->createWarehouse($tenant, $user, $branchId, 'AA', true);
        $b = $this->createWarehouse($tenant, $user, $branchId, 'BB', false);

        $this->assertSame(true, (bool) Warehouse::find($a)->is_default);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/warehouses/{$b}", [
                'name' => 'Almacen B editado',
                'is_default' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertSame(false, (bool) Warehouse::find($a)->is_default);
        $this->assertSame(true, (bool) Warehouse::find($b)->is_default);
    }

    public function test_index_orders_default_first_and_exposes_is_default(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $branchId = $this->branchId($tenant);

        $this->createWarehouse($tenant, $user, $branchId, 'BB', false);
        $this->createWarehouse($tenant, $user, $branchId, 'AA', true);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/warehouses');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        // El predeterminado (AA) va primero aunque orden alfabetico pondria BB primero.
        $this->assertSame('AA', $data[0]['code']);
        $this->assertTrue($data[0]['is_default']);
        $this->assertFalse($data[1]['is_default']);
    }

    public function test_default_is_isolated_between_tenants(): void
    {
        [$tenantA, $userA] = $this->seedTenantWithOwner('empresa-a');
        [$tenantB, $userB] = $this->seedTenantWithOwner('empresa-b');

        $a = $this->createWarehouse($tenantA, $userA, $this->branchId($tenantA), 'AAA', true);
        $b = $this->createWarehouse($tenantB, $userB, $this->branchId($tenantB), 'BBB', true);

        $this->assertSame(true, (bool) Warehouse::find($a)->is_default);
        $this->assertSame(true, (bool) Warehouse::find($b)->is_default);
    }

    // ---- Helpers ----

    private function branchId(Tenant $tenant): int
    {
        return (int) \DB::table('branches')->where('tenant_id', $tenant->id)->value('id');
    }

    private function createWarehouse(Tenant $tenant, User $user, int $branchId, string $code, bool $isDefault): int
    {
        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warehouses', [
                'branch_id' => $branchId,
                'name' => "Almacen {$code}",
                'code' => $code,
                'is_default' => $isDefault,
            ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function seedTenantWithOwner(string $slug = 'warehouse-demo'): array
    {
        $tenancy = app(TenantManager::class);

        $tenant = Tenant::firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Warehouse Demo']
        );
        $tenancy->set($tenant);
        setPermissionsTeamId($tenant->id);

        // Sucursal base para la FK branch_id.
        if (! \DB::table('branches')->where('tenant_id', $tenant->id)->exists()) {
            \DB::table('branches')->insert([
                'tenant_id' => $tenant->id,
                'code' => 'SUC',
                'name' => 'Sucursal',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user = User::firstOrCreate(
            ['email' => 'owner@warehouse.test'],
            [
                'name' => 'Owner User',
                'password' => bcrypt('secret'),
                'is_platform_admin' => false,
            ]
        );
        if (! $tenant->users()->where('users.id', $user->id)->exists()) {
            $tenant->users()->attach($user, ['status' => 'active']);
        }

        $ownerRole = Role::firstOrCreate(
            ['name' => 'Owner', 'guard_name' => 'web', 'tenant_id' => $tenant->id],
        );
        foreach (BasePermissions::PERMISSIONS as $permName) {
            $perm = Permission::firstOrCreate([
                'name' => $permName,
                'guard_name' => 'web',
            ]);
            if (! $ownerRole->hasPermissionTo($perm)) {
                $ownerRole->givePermissionTo($perm);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! $user->hasRole('Owner')) {
            $user->assignRole('Owner');
        }

        return [$tenant, $user];
    }
}
