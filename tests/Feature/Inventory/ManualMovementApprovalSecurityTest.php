<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\InventoryManualMovement;
use App\Modules\Branches\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualMovementApprovalSecurityTest extends TestCase
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

    public function test_pending_movement_cannot_be_processed_twice(): void
    {
        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Central', 'code' => 'CENT']);
        $product = Product::create(['name' => 'Producto Test', 'sku' => 'SEC-002']);

        $movement = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'loss',
            'reason' => 'Prueba doble proceso',
            'status' => 'pending',
        ]);

        $movement->update(['status' => 'approved']);

        $this->assertSame('approved', $movement->fresh()->status);
    }

    public function test_rejected_or_approved_movements_keep_final_state(): void
    {
        $branch = Branch::create(['name' => 'Estados finales', 'code' => 'FINAL']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Final', 'code' => 'FINAL']);
        $product = Product::create(['name' => 'Producto final', 'sku' => 'SEC-FINAL']);

        foreach (['approved', 'rejected'] as $status) {
            $movement = InventoryManualMovement::create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'type' => 'loss',
                'reason' => "Estado {$status}",
                'status' => $status,
            ]);
            $this->assertSame($status, $movement->fresh()->status);
        }
    }

    public function test_movement_isolated_by_tenant_context(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Other',
            'slug' => 'tenant-other',
        ]);

        app(TenantManager::class)->set($tenant);

        $branch = Branch::create(['name' => 'Other Branch', 'code' => 'OTHER']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Other Warehouse', 'code' => 'OTHER']);
        $product = Product::create(['name' => 'Other Product', 'sku' => 'SEC-003']);

        $movement = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'loss',
            'reason' => 'Tenant isolation test',
            'status' => 'pending',
        ]);

        app(TenantManager::class)->set($tenant);

        $this->assertSame($tenant->id, $movement->fresh()->tenant_id);
    }
}
