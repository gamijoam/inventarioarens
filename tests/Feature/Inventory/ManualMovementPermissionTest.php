<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryManualMovement;
use App\Modules\Branches\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualMovementPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Tenant Test',
            'slug' => 'tenant-test',
        ]);

        app(TenantManager::class)->set($tenant);
    }

    public function test_manual_movement_requires_creation_permission(): void
    {
        $tenant = app(TenantManager::class)->current();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Central', 'code' => 'CENT']);
        $product = Product::create(['name' => 'Producto Test', 'sku' => 'SEC-000']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory/manual-movements', [
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'type' => 'internal_consumption',
                'reason' => 'Sin permiso',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('inventory_manual_movements', ['reason' => 'Sin permiso']);
    }

    public function test_manual_movement_approval_security_flow_exists(): void
    {
        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Central', 'code' => 'CENT']);
        $product = Product::create(['name' => 'Producto Test', 'sku' => 'SEC-001']);

        $movement = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'internal_consumption',
            'reason' => 'Prueba seguridad',
            'status' => 'pending',
        ]);

        $this->assertSame('pending', $movement->status);
    }
}
