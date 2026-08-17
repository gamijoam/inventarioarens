<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosReservationExpiryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_reservation_is_released_once(): void
    {
        [$tenant, $order, $warehouse, $product] = $this->fixture();

        $this->artisan('inventory:expire-reservations', ['tenant' => $tenant->slug])
            ->assertExitCode(0);

        $this->assertSame(10.0, (float) StockBalance::query()->firstOrFail()->quantity_available);
        $this->assertSame(0.0, (float) StockBalance::query()->firstOrFail()->quantity_reserved);
        $this->assertSame(3, StockMovement::query()->count());
        $this->assertDatabaseHas('stock_movements', [
            'type' => 'released',
            'reference_type' => PosOrder::class,
            'reference_id' => $order->id,
        ]);
        $this->assertNull($order->fresh()->reserved_until);

        $this->artisan('inventory:expire-reservations', ['tenant' => $tenant->slug])
            ->assertExitCode(0);

        $this->assertSame(3, StockMovement::query()->count());
    }

    private function fixture(): array
    {
        $tenant = Tenant::create(['name' => 'Tenant Reservation Expiry', 'slug' => 'tenant-reservation-expiry']);
        app(TenantManager::class)->set($tenant);
        $user = User::factory()->create();
        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Central', 'code' => 'CENT']);
        $product = Product::create([
            'name' => 'Producto reservado',
            'sku' => 'RESERVATION-EXPIRY',
            'tracking_type' => 'quantity',
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
            'is_active' => true,
        ]);
        $sale = Sale::create([
            'status' => Sale::STATUS_DRAFT,
            'total_base_amount' => 30,
            'total_local_amount' => 0,
            'created_by' => $user->id,
        ]);
        $item = SaleItem::create([
            'sale_id' => $sale->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'sale_currency' => Product::CURRENCY_USD,
            'unit_price' => 10,
            'total_amount' => 30,
            'base_unit_price' => 10,
            'base_total_amount' => 30,
        ]);
        $order = PosOrder::create([
            'sale_id' => $sale->id,
            'status' => PosOrder::STATUS_OPEN,
            'cashier_id' => $user->id,
            'total_base_amount' => 30,
            'total_local_amount' => 0,
            'paid_base_amount' => 0,
            'paid_local_amount' => 0,
            'reserved_until' => now()->subMinute(),
        ]);
        $item->refresh();

        app(InventoryMovementService::class)->purchase($warehouse, $product, 10, null, $user, 'Saldo inicial');
        app(InventoryMovementService::class)->reserve(
            warehouse: $warehouse,
            product: $product,
            quantity: 3,
            createdBy: $user,
            reason: "Reserva POS #{$order->id}",
            referenceType: PosOrder::class,
            referenceId: $order->id,
        );

        return [$tenant, $order, $warehouse, $product];
    }
}
