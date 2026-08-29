<?php

namespace Tests\Feature\Inventory;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_inventory_drift_and_fixes_it_only_with_fix(): void
    {
        [$tenant, $warehouse, $product] = $this->inventoryContext('reconcile-drift');
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 7,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        $this->artisan('inventory:reconcile', ['tenant' => $tenant->slug])
            ->expectsOutputToContain('1 drift')
            ->assertExitCode(1);
        $this->assertEquals(7, (float) $this->balance($warehouse, $product)->quantity_available);

        $this->artisan('inventory:reconcile', ['tenant' => $tenant->slug, '--fix' => true])
            ->expectsOutputToContain('1 fixed')
            ->assertExitCode(0);
        $this->assertEquals(10, (float) $this->balance($warehouse, $product)->quantity_available);
    }

    public function test_dry_run_reports_fixable_drift_without_changing_balance(): void
    {
        [$tenant, $warehouse, $product] = $this->inventoryContext('reconcile-dry-run');
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);

        $this->artisan('inventory:reconcile', [
            'tenant' => $tenant->slug,
            '--fix' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('1 drift')
            ->assertExitCode(1);

        $this->assertEquals(1, (float) $this->balance($warehouse, $product)->quantity_available);
    }

    public function test_reconciliation_accounts_for_reserved_and_released_movements(): void
    {
        [$tenant, $warehouse, $product] = $this->inventoryContext('reconcile-reserved');
        foreach ([
            ['type' => 'purchase', 'quantity' => 10],
            ['type' => 'reserved', 'quantity' => 3],
            ['type' => 'released', 'quantity' => 1],
        ] as $movement) {
            StockMovement::create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                ...$movement,
            ]);
        }
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 8,
            'quantity_reserved' => 2,
        ]);

        $this->artisan('inventory:reconcile', ['tenant' => $tenant->slug])
            ->expectsOutputToContain('0 drift')
            ->assertExitCode(0);
    }

    public function test_reconciliation_for_one_tenant_does_not_modify_another_tenant(): void
    {
        [$tenantA, $warehouseA, $productA] = $this->inventoryContext('reconcile-a');
        [$tenantB, $warehouseB, $productB] = $this->inventoryContext('reconcile-b');
        $this->useTenant($tenantA);
        StockMovement::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $productA->id,
            'type' => 'purchase',
            'quantity' => 4,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $productA->id,
            'quantity_available' => 0,
        ]);
        $this->useTenant($tenantB);
        StockMovement::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $productB->id,
            'type' => 'purchase',
            'quantity' => 8,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $productB->id,
            'quantity_available' => 0,
        ]);

        $this->artisan('inventory:reconcile', [
            'tenant' => $tenantA->slug,
            '--fix' => true,
        ])->assertExitCode(0);

        $this->useTenant($tenantA);
        $this->assertEquals(4, (float) $this->balance($warehouseA, $productA)->quantity_available);
        $this->useTenant($tenantB);
        $this->assertEquals(0, (float) $this->balance($warehouseB, $productB)->quantity_available);
    }

    public function test_reconciliation_detects_and_optionally_fixes_serial_unit_drift(): void
    {
        [$tenant, $warehouse, $product] = $this->inventoryContext('reconcile-serial');
        $product->update(['tracking_type' => Product::TRACKING_SERIALIZED]);
        ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-RECON-001',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-RECON-002',
            'status' => ProductUnit::STATUS_RESERVED,
        ]);
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 2,
        ]);
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'reserved',
            'quantity' => 1,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 3,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        $this->artisan('inventory:reconcile', ['tenant' => $tenant->slug])
            ->expectsOutputToContain('1 serial drift')
            ->assertExitCode(1);
        $this->assertEquals(3, (float) $this->balance($warehouse, $product)->quantity_available);

        $this->artisan('inventory:reconcile', [
            'tenant' => $tenant->slug,
            '--fix' => true,
            '--fix-serials' => true,
        ])
            ->expectsOutputToContain('1 fixed')
            ->assertExitCode(0);
        $balance = $this->balance($warehouse, $product);
        $this->assertEquals(1, (float) $balance->quantity_available);
        $this->assertEquals(1, (float) $balance->quantity_reserved);
    }

    public function test_reconciliation_never_projects_negative_operational_stock(): void
    {
        [$tenant, $warehouse, $product] = $this->inventoryContext('reconcile-negative-ledger');
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'quantity' => 4,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 0,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        $this->artisan('inventory:reconcile', ['tenant' => $tenant->slug])
            ->expectsOutputToContain('0 drift')
            ->assertExitCode(0);
    }

    private function inventoryContext(string $slug): array
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug]);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $branch = Branch::create(['name' => "Branch {$slug}", 'code' => strtoupper(substr($slug, 0, 8))]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => "Warehouse {$slug}",
            'code' => strtoupper(substr($slug, 0, 8)),
        ]);
        $product = Product::create([
            'name' => "Product {$slug}",
            'sku' => strtoupper($slug),
        ]);

        return [$tenant, $warehouse, $product];
    }

    private function balance(Warehouse $warehouse, Product $product): StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
