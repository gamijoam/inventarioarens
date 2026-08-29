<?php

namespace Tests\Feature\Inventory;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationExpiryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_reservation_releases_stock_and_serial_unit(): void
    {
        [$tenant, $warehouse, $product, $unit] = $this->context('reservation-expiry');
        $movement = app(InventoryMovementService::class)->reserve(
            warehouse: $warehouse,
            product: $product,
            quantity: 1,
            reason: 'Reserva vencible',
            expiresAt: now()->subMinute(),
        );
        $unit->update([
            'status' => ProductUnit::STATUS_RESERVED,
            'released_stock_movement_id' => $movement->id,
        ]);

        $this->artisan('inventory:expire-reservations', ['tenant' => $tenant->slug])
            ->expectsOutputToContain('1 expired')
            ->assertExitCode(0);

        $balance = $this->balance($warehouse, $product);
        $this->assertEquals(10, (float) $balance->quantity_available);
        $this->assertEquals(0, (float) $balance->quantity_reserved);
        $this->assertSame(ProductUnit::STATUS_AVAILABLE, $unit->refresh()->status);
        $this->assertNull($unit->released_stock_movement_id);
        $this->assertDatabaseHas('stock_movements', [
            'type' => 'released',
            'reference_type' => 'inventory_reservation_expired',
            'reference_id' => $movement->id,
        ]);
    }

    public function test_expiration_is_idempotent_and_does_not_touch_active_reservations(): void
    {
        [$tenant, $warehouse, $product] = $this->context('reservation-active');
        app(InventoryMovementService::class)->reserve(
            warehouse: $warehouse,
            product: $product,
            quantity: 1,
            reason: 'Reserva activa',
            expiresAt: now()->addHour(),
        );

        $this->artisan('inventory:expire-reservations', ['tenant' => $tenant->slug])
            ->expectsOutputToContain('0 expired')
            ->assertExitCode(0);
        $this->artisan('inventory:expire-reservations', ['tenant' => $tenant->slug])
            ->expectsOutputToContain('0 expired')
            ->assertExitCode(0);

        $this->assertEquals(9, (float) $this->balance($warehouse, $product)->quantity_available);
        $this->assertEquals(1, (float) $this->balance($warehouse, $product)->quantity_reserved);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_expiration_for_one_tenant_does_not_release_another_tenants_reservation(): void
    {
        [$tenantA, $warehouseA, $productA] = $this->context('reservation-tenant-a');
        app(InventoryMovementService::class)->reserve(
            warehouse: $warehouseA,
            product: $productA,
            quantity: 1,
            expiresAt: now()->subMinute(),
        );

        [$tenantB, $warehouseB, $productB] = $this->context('reservation-tenant-b');
        app(InventoryMovementService::class)->reserve(
            warehouse: $warehouseB,
            product: $productB,
            quantity: 1,
            expiresAt: now()->subMinute(),
        );

        $this->artisan('inventory:expire-reservations', ['tenant' => $tenantA->slug])
            ->expectsOutputToContain('1 expired')
            ->assertExitCode(0);

        app(TenantManager::class)->set($tenantA);
        $this->assertEquals(10, (float) $this->balance($warehouseA, $productA)->quantity_available);
        $this->assertEquals(0, (float) $this->balance($warehouseA, $productA)->quantity_reserved);
        app(TenantManager::class)->set($tenantB);
        $this->assertEquals(9, (float) $this->balance($warehouseB, $productB)->quantity_available);
        $this->assertEquals(1, (float) $this->balance($warehouseB, $productB)->quantity_reserved);
    }

    public function test_same_reference_releases_are_allocated_to_the_oldest_reservation_first(): void
    {
        [$tenant, $warehouse, $product] = $this->context('reservation-fifo');
        $first = app(InventoryMovementService::class)->reserve(
            warehouse: $warehouse,
            product: $product,
            quantity: 1,
            referenceType: 'hold',
            referenceId: 77,
            expiresAt: now()->subMinutes(2),
        );
        $second = app(InventoryMovementService::class)->reserve(
            warehouse: $warehouse,
            product: $product,
            quantity: 1,
            referenceType: 'hold',
            referenceId: 77,
            expiresAt: now()->subMinute(),
        );
        app(InventoryMovementService::class)->release(
            warehouse: $warehouse,
            product: $product,
            quantity: 1,
            referenceType: 'hold',
            referenceId: 77,
        );

        $this->artisan('inventory:expire-reservations', ['tenant' => $tenant->slug])
            ->expectsOutputToContain('1 expired')
            ->assertExitCode(0);

        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'inventory_reservation_expired',
            'reference_id' => $second->id,
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'reference_type' => 'inventory_reservation_expired',
            'reference_id' => $first->id,
        ]);
        $this->assertEquals(10, (float) $this->balance($warehouse, $product)->quantity_available);
        $this->assertEquals(0, (float) $this->balance($warehouse, $product)->quantity_reserved);
    }

    private function context(string $slug): array
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
            'tracking_type' => Product::TRACKING_SERIALIZED,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 10,
        ]);
        $unit = ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => "IMEI-{$slug}",
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);

        return [$tenant, $warehouse, $product, $unit];
    }

    private function balance(Warehouse $warehouse, Product $product): StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }
}
