<?php

namespace Tests\Feature\Inventory;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerializedProductUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_serialized_product_can_have_many_unique_imeis_inside_current_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Telefonos Arens', 'slug' => 'telefonos-arens']);
        [$warehouse, $product] = $this->warehouseAndSerializedProduct($tenant, 'A06');

        for ($index = 1; $index <= 30; $index++) {
            ProductUnit::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                'serial_number' => sprintf('IMEI-A06-%02d', $index),
            ]);
        }

        $this->assertSame(Product::TRACKING_SERIALIZED, $product->tracking_type);
        $this->assertTrue($product->requiresSerializedTracking());
        $this->assertSame(30, $product->units()->count());
        $this->assertDatabaseCount('product_units', 30);
    }

    public function test_product_units_are_scoped_and_serials_are_unique_per_tenant(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        [$warehouseA, $productA] = $this->warehouseAndSerializedProduct($tenantA, 'A');
        ProductUnit::create([
            'product_id' => $productA->id,
            'warehouse_id' => $warehouseA->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-SHARED',
        ]);

        [$warehouseB, $productB] = $this->warehouseAndSerializedProduct($tenantB, 'B');
        ProductUnit::create([
            'product_id' => $productB->id,
            'warehouse_id' => $warehouseB->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-SHARED',
        ]);

        $this->assertSame(['IMEI-SHARED'], ProductUnit::query()->pluck('serial_number')->all());

        $this->expectException(QueryException::class);

        ProductUnit::create([
            'product_id' => $productB->id,
            'warehouse_id' => $warehouseB->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-SHARED',
        ]);
    }

    public function test_product_unit_cannot_reference_product_or_warehouse_from_another_tenant(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        [$warehouseA] = $this->warehouseAndSerializedProduct($tenantA, 'A');
        [, $productB] = $this->warehouseAndSerializedProduct($tenantB, 'B');

        $this->useTenant($tenantA);

        $this->expectException(QueryException::class);

        ProductUnit::create([
            'product_id' => $productB->id,
            'warehouse_id' => $warehouseA->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-CROSS-TENANT',
        ]);
    }

    public function test_product_unit_cannot_reference_stock_movement_from_another_tenant(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        [$warehouseA, $productA] = $this->warehouseAndSerializedProduct($tenantA, 'A');
        [$warehouseB, $productB] = $this->warehouseAndSerializedProduct($tenantB, 'B');

        $movementB = StockMovement::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $productB->id,
            'type' => 'purchase',
            'quantity' => 1,
        ]);

        $this->useTenant($tenantA);

        $this->expectException(QueryException::class);

        ProductUnit::create([
            'product_id' => $productA->id,
            'warehouse_id' => $warehouseA->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-CROSS-MOVEMENT',
            'acquired_stock_movement_id' => $movementB->id,
        ]);
    }

    private function warehouseAndSerializedProduct(Tenant $tenant, string $suffix): array
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
            'name' => "Samsung A06 {$suffix}",
            'sku' => "SAMSUNG-A06-{$suffix}",
            'tracking_type' => Product::TRACKING_SERIALIZED,
        ]);

        return [$warehouse, $product];
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }
}
