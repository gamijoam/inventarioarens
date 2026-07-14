<?php

namespace Tests\Feature\Inventory;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryValuationTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $branch = Branch::create(['name' => 'B', 'code' => 'B1']);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W1',
            'code' => 'W1',
        ]);

        return [$tenant, $branch, $warehouse];
    }

    private function product(int $tenantId, string $sku = 'P-1'): Product
    {
        return Product::create([
            'tenant_id' => $tenantId,
            'name' => 'P',
            'sku' => $sku,
            'tracking_type' => 'quantity',
        ]);
    }

    public function test_wac_after_single_purchase(): void
    {
        [$tenant, , $warehouse] = $this->setupTenant();
        $product = $this->product($tenant->id);

        StockMovement::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 10,
            'unit_cost' => 5.00,
            'created_by' => null,
        ]);

        $service = app(InventoryValuationService::class);
        $wac = $service->recalculate($product);

        $this->assertEquals(5.00, $wac);
        $product->refresh();
        $this->assertEquals(5.00, (float) $product->average_cost);
    }

    public function test_wac_blends_multiple_purchases(): void
    {
        [$tenant, , $warehouse] = $this->setupTenant();
        $product = $this->product($tenant->id);

        StockMovement::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 10,
            'unit_cost' => 4.00,
        ]);
        StockMovement::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 10,
            'unit_cost' => 6.00,
        ]);

        $wac = app(InventoryValuationService::class)->recalculate($product);

        $this->assertEquals(5.00, $wac);
    }

    public function test_wac_returns_null_when_no_cost_movements(): void
    {
        [$tenant, , $warehouse] = $this->setupTenant();
        $product = $this->product($tenant->id);

        StockMovement::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'adjustment_in',
            'quantity' => 5,
            'unit_cost' => null,
        ]);

        $wac = app(InventoryValuationService::class)->recalculate($product);

        $this->assertNull($wac);
        $product->refresh();
        $this->assertNull($product->average_cost);
    }
}
