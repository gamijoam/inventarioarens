<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryManualMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManualMovementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_can_list_manual_movements(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant API',
            'slug' => 'tenant-api',
        ]);

        $this->useTenant($tenant);

        [$warehouse, $product] = $this->createWarehouseProduct();

        $user = $this->createUser($tenant);

        $this->grantRole(
            $tenant,
            $user,
            'Almacen',
            ['inventory.manual_movements.view']
        );

        InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'type' => 'loss',
            'reason' => 'Prueba API',
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory/manual-movements');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
            ]);

        $this->assertCount(
            1,
            $response->json('data')
        );
    }


    public function test_can_filter_manual_movements_by_status(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Filter',
            'slug' => 'tenant-filter',
        ]);

        $this->useTenant($tenant);

        [$warehouse, $product] = $this->createWarehouseProduct();

        $user = $this->createUser($tenant);

        $this->grantRole($tenant, $user, 'Consulta', ['inventory.manual_movements.view']);

        InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'type' => 'loss',
            'reason' => 'Pendiente',
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'loss',
            'reason' => 'Aprobado',
            'status' => 'approved',
            'created_by' => $user->id,
        ]);


        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson(
                '/api/inventory/manual-movements?status=pending'
            );


        $response
            ->assertOk();


        $this->assertCount(
            1,
            $response->json('data')
        );


        $this->assertSame(
            'pending',
            $response->json('data.0.status')
        );
    }


    public function test_can_show_manual_movement_detail_with_audit_data(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Detail',
            'slug' => 'tenant-detail',
        ]);

        $this->useTenant($tenant);

        [$warehouse, $product] = $this->createWarehouseProduct();

        $user = $this->createUser($tenant);

        $this->grantRole($tenant, $user, 'Consulta', ['inventory.manual_movements.view']);

        $movement = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'type' => 'internal_consumption',
            'reason' => 'Detalle API',
            'status' => 'approved',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);


        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson(
                "/api/inventory/manual-movements/{$movement->id}"
            );


        $response
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $movement->id
            )
            ->assertJsonPath(
                'data.status',
                'approved'
            );
    }


    private function createWarehouseProduct(): array
    {
        $branch = Branch::create([
            'name' => 'Sucursal API',
            'code' => 'API',
        ]);

        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Almacen API',
            'code' => 'WH-API',
        ]);

        $product = Product::create([
            'name' => 'Producto API',
            'sku' => 'API-001',
        ]);

        return [
            $warehouse,
            $product,
        ];
    }


    private function createUser(Tenant $tenant): User
    {
        $user = User::factory()->create();

        $user
            ->tenants()
            ->attach(
                $tenant,
                [
                    'status' => 'active'
                ]
            );

        return $user;
    }


    private function grantRole(
        Tenant $tenant,
        User $user,
        string $roleName,
        array $permissions
    ): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate(
            $roleName,
            'web'
        );

        $role->syncPermissions($permissions);

        $user->assignRole($role);
    }


    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)
            ->set($tenant);

        setPermissionsTeamId(
            $tenant->id
        );
    }
}
