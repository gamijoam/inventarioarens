<?php

namespace Tests\Feature\Inventory;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Exceptions\CrossTenantInventoryReferenceException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidStockQuantityException;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_creates_movement_and_increases_available_balance(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct('A');

        $movement = $this->service()->purchase($warehouse, $product, 10, 80, reason: 'Compra inicial');

        $balance = $this->balance($warehouse, $product);

        $this->assertSame('purchase', $movement->type);
        $this->assertSame(10.0, (float) $movement->quantity);
        $this->assertSame(80.0, (float) $movement->unit_cost);
        $this->assertSame(10.0, (float) $balance->quantity_available);
        $this->assertSame(0.0, (float) $balance->quantity_reserved);
        $this->assertSame(0.0, (float) $balance->quantity_damaged);
    }

    public function test_sale_decreases_available_balance_and_requires_stock(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct('A');

        $this->service()->purchase($warehouse, $product, 10);
        $movement = $this->service()->sale($warehouse, $product, 4);

        $this->assertSame('sale', $movement->type);
        $this->assertSame(6.0, (float) $this->balance($warehouse, $product)->quantity_available);

        $this->expectException(InsufficientStockException::class);

        $this->service()->sale($warehouse, $product, 7);
    }

    public function test_reserve_release_and_damage_move_quantities_between_balance_buckets(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct('A');

        $this->service()->purchase($warehouse, $product, 10);
        $this->service()->reserve($warehouse, $product, 3);
        $this->service()->release($warehouse, $product, 1);
        $this->service()->markDamaged($warehouse, $product, 2);

        $balance = $this->balance($warehouse, $product);

        $this->assertSame(6.0, (float) $balance->quantity_available);
        $this->assertSame(2.0, (float) $balance->quantity_reserved);
        $this->assertSame(2.0, (float) $balance->quantity_damaged);
        $this->assertSame(['purchase', 'reserved', 'released', 'damaged'], StockMovement::query()->orderBy('id')->pluck('type')->all());
    }

    public function test_transfer_creates_out_and_in_movements_and_updates_both_warehouses(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $fromWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen', 'code' => 'FROM']);
        $toWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino', 'code' => 'TO']);
        $product = Product::create(['name' => 'Redmi A3', 'sku' => 'REDMI-A3']);

        $this->service()->purchase($fromWarehouse, $product, 10);
        $movements = $this->service()->transfer($fromWarehouse, $toWarehouse, $product, 4);

        $this->assertSame(['transfer_out', 'transfer_in'], array_map(
            static fn (StockMovement $movement): string => $movement->type,
            $movements,
        ));
        $this->assertSame(6.0, (float) $this->balance($fromWarehouse, $product)->quantity_available);
        $this->assertSame(4.0, (float) $this->balance($toWarehouse, $product)->quantity_available);
    }

    public function test_inventory_operations_reject_invalid_quantity(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct('A');

        $this->expectException(InvalidStockQuantityException::class);

        $this->service()->purchase($warehouse, $product, 0);
    }

    public function test_inventory_service_rejects_cross_tenant_models_before_writing(): void
    {
        [$warehouseA] = $this->warehouseAndProduct('A');
        [, $productB] = $this->warehouseAndProduct('B');

        $this->useTenant($warehouseA->tenant);

        $this->expectException(CrossTenantInventoryReferenceException::class);

        $this->service()->purchase($warehouseA, $productB, 1);
    }

    private function warehouseAndProduct(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Tenant {$suffix}", 'slug' => "tenant-{$suffix}"]);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$suffix}", 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$suffix}", 'code' => 'WH']);
        $product = Product::create(['name' => "Producto {$suffix}", 'sku' => 'SKU']);

        return [$warehouse, $product];
    }

    private function balance(Warehouse $warehouse, Product $product): StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    private function service(): InventoryMovementService
    {
        return app(InventoryMovementService::class);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }
}
