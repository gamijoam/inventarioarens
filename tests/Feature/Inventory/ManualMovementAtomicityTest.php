<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryManualMovement;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
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

class ManualMovementAtomicityTest extends TestCase
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

    public function test_approval_retry_with_same_key_creates_one_stock_effect(): void
    {
        [$tenant, $warehouse, $product, $user] = $this->fixture();
        app(InventoryMovementService::class)->purchase($warehouse, $product, 10, null, $user, 'Saldo inicial');

        $manual = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'type' => 'adjustment_out',
            'reason' => 'Consumo interno',
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        $first = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('Idempotency-Key', 'manual-approval-1')
            ->postJson("/api/inventory/manual-movements/{$manual->id}/approve")
            ->assertOk();
        $second = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('Idempotency-Key', 'manual-approval-1')
            ->postJson("/api/inventory/manual-movements/{$manual->id}/approve")
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('approved', $manual->fresh()->status);
        $this->assertSame(7.0, (float) StockBalance::query()->firstOrFail()->quantity_available);
        $this->assertSame(2, StockMovement::query()->count());
        $this->assertNotNull($manual->fresh()->stock_movement_id);
    }

    public function test_approval_with_insufficient_stock_keeps_manual_movement_pending(): void
    {
        [$tenant, $warehouse, $product, $user] = $this->fixture();
        app(InventoryMovementService::class)->purchase($warehouse, $product, 2, null, $user, 'Saldo inicial');

        $manual = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'type' => 'adjustment_out',
            'reason' => 'Salida sin saldo',
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('Idempotency-Key', 'manual-approval-insufficient')
            ->postJson("/api/inventory/manual-movements/{$manual->id}/approve")
            ->assertStatus(422);

        $this->assertSame('pending', $manual->fresh()->status);
        $this->assertSame(2.0, (float) StockBalance::query()->firstOrFail()->quantity_available);
        $this->assertSame(1, StockMovement::query()->count());
    }

    private function fixture(): array
    {
        $tenant = Tenant::create(['name' => 'Tenant Manual Atomic', 'slug' => 'tenant-manual-atomic']);
        app(TenantManager::class)->set($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Central', 'code' => 'CENT']);
        $product = Product::create(['name' => 'Producto manual', 'sku' => 'MANUAL-ATOMIC']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $role = Role::findOrCreate('Almacen Manual Atomic', 'web');
        $role->syncPermissions(['inventory.manual_movements.approve', 'inventory.manual_movements.view']);
        setPermissionsTeamId($tenant->id);
        $user->assignRole($role);

        return [$tenant, $warehouse, $product, $user];
    }
}
