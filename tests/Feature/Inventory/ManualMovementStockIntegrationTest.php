<?php

namespace Tests\Feature\Inventory;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryManualMovement;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\AuthorizedInventoryMovementService;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualMovementStockIntegrationTest extends TestCase
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

    public function test_approved_manual_exit_reduces_stock(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct();

        app(InventoryMovementService::class)
            ->purchase($warehouse, $product, 10);

        $movement = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'type' => 'adjustment_out',
            'reason' => 'Consumo interno',
            'status' => 'pending',
        ]);

        $movement->update(['status' => 'approved']);

        $this->assertSame(10.0, (float) StockBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first()->quantity_available);
    }

    public function test_approved_manual_entry_increases_stock(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct();

        app(InventoryMovementService::class)
            ->purchase($warehouse, $product, 5);

        $movement = InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 4,
            'type' => 'adjustment_in',
            'reason' => 'Producto encontrado',
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('inventory_manual_movements', [
            'id' => $movement->id,
            'status' => 'approved',
        ]);
    }

    public function test_rejected_manual_movement_does_not_modify_stock(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct();

        app(InventoryMovementService::class)
            ->purchase($warehouse, $product, 20);

        InventoryManualMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'type' => 'adjustment_out',
            'reason' => 'Pérdida rechazada',
            'status' => 'rejected',
        ]);

        $this->assertSame(20.0, (float) StockBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first()->quantity_available);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    private function warehouseAndProduct(): array
    {
        $branch = Branch::create([
            'name' => 'Principal',
            'code' => 'MAIN',
        ]);

        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Central',
            'code' => 'CENT',
        ]);

        $product = Product::create([
            'name' => 'Producto Test',
            'sku' => uniqid('SKU-'),
        ]);

        return [$warehouse, $product];
    }
}
