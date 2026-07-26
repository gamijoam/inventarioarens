<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\InventoryManualMovement;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Branches\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualMovementWorkflowTest extends TestCase
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

    public function test_manual_movement_is_created_as_pending_without_changing_stock(): void
    {
        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Central', 'code' => 'CENT']);
        $product = Product::create(['name' => 'Producto Test', 'sku' => 'TEST-001']);

        InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'type' => 'internal_consumption',
            'reason' => 'Uso interno',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('inventory_manual_movements', [
            'status' => 'pending',
            'quantity' => 5,
        ]);

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_approved_manual_movement_generates_stock_movement(): void
    {
        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Central', 'code' => 'CENT']);
        $product = Product::create(['name' => 'Producto Test', 'sku' => 'TEST-002']);

        $movement = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'internal_consumption',
            'reason' => 'Consumo interno autorizado',
            'status' => 'pending',
        ]);

        $this->assertSame('pending', $movement->status);

        $movement->update(['status' => 'approved']);

        $this->assertDatabaseHas('inventory_manual_movements', [
            'id' => $movement->id,
            'status' => 'approved',
        ]);
    }

    public function test_rejected_manual_movement_does_not_create_stock_movement(): void
    {
        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Central', 'code' => 'CENT']);
        $product = Product::create(['name' => 'Producto Test', 'sku' => 'TEST-003']);

        $movement = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'type' => 'loss',
            'reason' => 'Producto perdido durante operación',
            'status' => 'rejected',
        ]);

        $this->assertDatabaseHas('inventory_manual_movements', [
            'id' => $movement->id,
            'status' => 'rejected',
        ]);

        $this->assertDatabaseCount('stock_movements', 0);
    }
}
