<?php

namespace Tests\Feature\Warehouses;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warehouses\Models\WarehouseLocation;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WarehouseLocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('warehouses.view', 'web');
        Permission::findOrCreate('warehouses.update', 'web');
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }

    private function bootstrap(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->useTenant($tenant);
        $branch = Branch::create(['name' => 'B', 'code' => 'B1']);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W1',
            'code' => 'W1',
        ]);

        $user = User::create([
            'name' => 'A', 'email' => 'a@t.test', 'password' => bcrypt('secret'),
        ]);
        $user->tenants()->attach($tenant->id, ['status' => 'active']);
        $user->givePermissionTo(['warehouses.view', 'warehouses.update']);

        return [$tenant, $warehouse, $user];
    }

    public function test_can_create_root_location(): void
    {
        [$tenant, $warehouse, $user] = $this->bootstrap();

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/warehouses/{$warehouse->id}/locations", [
                'name' => 'Pasillo A',
                'code' => 'A',
                'aisle' => 'A',
                'rack' => '1',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Pasillo A')
            ->assertJsonPath('data.aisle', 'A')
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.full_path', 'Pasillo A');
    }

    public function test_can_create_child_location(): void
    {
        [$tenant, $warehouse, $user] = $this->bootstrap();
        $root = WarehouseLocation::create([
            'warehouse_id' => $warehouse->id,
            'name' => 'Pasillo A',
            'code' => 'A',
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/warehouses/{$warehouse->id}/locations", [
                'name' => 'Estante 1',
                'code' => 'A-E1',
                'parent_id' => $root->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.parent_id', $root->id)
            ->assertJsonPath('data.full_path', 'Pasillo A / Estante 1');
    }

    public function test_code_must_be_unique_per_warehouse(): void
    {
        [$tenant, $warehouse, $user] = $this->bootstrap();
        $root = WarehouseLocation::create([
            'warehouse_id' => $warehouse->id,
            'name' => 'Pasillo A',
            'code' => 'A',
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/warehouses/{$warehouse->id}/locations", [
                'name' => 'Duplicate',
                'code' => 'A',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_location_from_other_warehouse_returns_404(): void
    {
        [$tenant, $warehouse, $user] = $this->bootstrap();
        $otherBranch = Branch::create(['name' => 'B2', 'code' => 'B2']);
        $otherWarehouse = Warehouse::create([
            'branch_id' => $otherBranch->id,
            'name' => 'W2', 'code' => 'W2',
        ]);
        $foreign = WarehouseLocation::create([
            'warehouse_id' => $otherWarehouse->id,
            'name' => 'X', 'code' => 'X',
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/warehouses/{$warehouse->id}/locations/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_can_list_locations_with_roots_only_filter(): void
    {
        [$tenant, $warehouse, $user] = $this->bootstrap();
        $root = WarehouseLocation::create([
            'warehouse_id' => $warehouse->id, 'name' => 'Root', 'code' => 'R',
        ]);
        WarehouseLocation::create([
            'warehouse_id' => $warehouse->id, 'name' => 'Child', 'code' => 'C',
            'parent_id' => $root->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/warehouses/{$warehouse->id}/locations?roots_only=1");

        $this->assertCount(1, $response->json('data'));
    }
}
