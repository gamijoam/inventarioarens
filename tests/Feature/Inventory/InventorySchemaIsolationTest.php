<?php

namespace Tests\Feature\Inventory;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventorySchemaIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_branches_and_warehouses_are_scoped_to_current_tenant(): void
    {
        [$tenantA, $tenantB] = $this->tenants();

        $this->useTenant($tenantA);
        $branchA = Branch::create(['name' => 'Principal A', 'code' => 'MAIN']);
        Warehouse::create(['branch_id' => $branchA->id, 'name' => 'Almacen A', 'code' => 'WH']);

        $this->useTenant($tenantB);
        $branchB = Branch::create(['name' => 'Principal B', 'code' => 'MAIN']);
        Warehouse::create(['branch_id' => $branchB->id, 'name' => 'Almacen B', 'code' => 'WH']);

        $this->assertSame(['Principal B'], Branch::query()->pluck('name')->all());
        $this->assertSame(['Almacen B'], Warehouse::query()->pluck('name')->all());

        $this->useTenant($tenantA);
        $this->assertSame(['Principal A'], Branch::query()->pluck('name')->all());
        $this->assertSame(['Almacen A'], Warehouse::query()->pluck('name')->all());
    }

    public function test_stock_movements_and_balances_are_scoped_to_current_tenant(): void
    {
        [$tenantA, $tenantB] = $this->tenants();

        [$warehouseA, $productA] = $this->warehouseAndProduct($tenantA, 'A');
        StockMovement::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $productA->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $productA->id,
            'quantity_available' => 10,
        ]);

        [$warehouseB, $productB] = $this->warehouseAndProduct($tenantB, 'B');
        StockMovement::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $productB->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $productB->id,
            'quantity_available' => 5,
        ]);

        $this->assertSame([5.0], $this->numericValues(StockMovement::query()->pluck('quantity')->all()));
        $this->assertSame([5.0], $this->numericValues(StockBalance::query()->pluck('quantity_available')->all()));

        $this->useTenant($tenantA);
        $this->assertSame([10.0], $this->numericValues(StockMovement::query()->pluck('quantity')->all()));
        $this->assertSame([10.0], $this->numericValues(StockBalance::query()->pluck('quantity_available')->all()));
    }

    public function test_inventory_codes_and_balances_are_unique_per_tenant(): void
    {
        [$tenantA, $tenantB] = $this->tenants();

        [$warehouseA, $productA] = $this->warehouseAndProduct($tenantA, 'SHARED');
        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $productA->id,
            'quantity_available' => 1,
        ]);

        [$warehouseB, $productB] = $this->warehouseAndProduct($tenantB, 'SHARED');
        StockBalance::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $productB->id,
            'quantity_available' => 2,
        ]);

        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseCount('warehouses', 2);
        $this->assertDatabaseCount('stock_balances', 2);
    }

    public function test_stock_movement_cannot_reference_product_from_another_tenant(): void
    {
        [$tenantA, $tenantB] = $this->tenants();

        [$warehouseA] = $this->warehouseAndProduct($tenantA, 'A');
        [, $productB] = $this->warehouseAndProduct($tenantB, 'B');

        $this->useTenant($tenantA);

        $this->expectException(QueryException::class);

        StockMovement::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $productB->id,
            'type' => 'purchase',
            'quantity' => 1,
        ]);
    }

    private function tenants(): array
    {
        return [
            Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']),
            Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']),
        ];
    }

    private function warehouseAndProduct(Tenant $tenant, string $suffix): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create([
            'name' => "Sucursal {$suffix}",
            'code' => 'MAIN',
        ]);

        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => "Almacen {$suffix}",
            'code' => 'WH',
        ]);

        $product = Product::create([
            'name' => "Producto {$suffix}",
            'sku' => 'SKU',
        ]);

        return [$warehouse, $product];
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }

    private function numericValues(array $values): array
    {
        return array_map(static fn ($value): float => (float) $value, $values);
    }
}
