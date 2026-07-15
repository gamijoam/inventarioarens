<?php

namespace Tests\Feature\InventoryTransfers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Models\TenantTransferSetting;
use App\Modules\InventoryTransfers\Services\InventoryTransferService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FASE T1 fix (audit C3): implementa reserve_on_request del
 * TenantTransferSetting. Si el tenant lo activa, al CREAR un traslado
 * logistico se reserva el stock inmediatamente (movement 'reserved' +
 * ProductUnit.status = RESERVED) en vez de esperar al 'prepare'.
 *
 * Modela empresas con protocolo estricto donde el stock se aparta al
 * solicitar el traslado.
 */
class ReserveOnRequestTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(bool $reserveOnRequest): array
    {
        $tenant = Tenant::create(['name' => 'Tienda Reserva', 'slug' => 'tienda-reserva']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        TenantTransferSetting::create([
            'tenant_id' => $tenant->id,
            'validation_mode' => TenantTransferSetting::MODE_LOGISTICS,
            'reserve_on_request' => $reserveOnRequest,
            'require_preparation_checklist' => false,
            'require_reception_checklist' => false,
        ]);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-RSV']);
        $from = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen', 'code' => 'WH-RSV-ORIG']);
        $to = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino', 'code' => 'WH-RSV-DEST']);

        $product = \App\Modules\Products\Models\Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-RSV-'.uniqid(),
            'tracking_type' => \App\Modules\Products\Models\Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => \App\Modules\Products\Models\Product::CURRENCY_USD,
        ]);

        app(InventoryMovementService::class)->purchase($from, $product, 10, 5, $user, 'Stock inicial');

        return [$user, $from, $to, $product];
    }

    public function test_reserve_on_request_creates_reserved_movement_at_creation(): void
    {
        [$user, $from, $to, $product] = $this->setupTenant(reserveOnRequest: true);

        $service = app(InventoryTransferService::class);
        $transfer = $service->create($user, [
            'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'reason' => 'Test',
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]);

        // El item debe tener prepared_quantity = quantity (reservado en create).
        $this->assertEquals(5, (float) $transfer->items[0]->prepared_quantity);

        // El stock del warehouse origen debe tener quantity_reserved = 5.
        $balance = \App\Modules\Inventory\Models\StockBalance::query()
            ->where('warehouse_id', $from->id)
            ->where('product_id', $product->id)
            ->first();
        $this->assertEquals(5, (float) $balance->quantity_reserved);
        $this->assertEquals(5, (float) $balance->quantity_available, 'El stock sigue disponible en origen, solo reservado');
    }

    public function test_reserve_on_request_off_does_not_reserve_at_creation(): void
    {
        [$user, $from, $to, $product] = $this->setupTenant(reserveOnRequest: false);

        $service = app(InventoryTransferService::class);
        $transfer = $service->create($user, [
            'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'reason' => 'Test',
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]);

        // Sin reserve_on_request, prepared_quantity = 0 (esperar a prepare).
        $this->assertEquals(0, (float) $transfer->items[0]->prepared_quantity);

        // El stock NO esta reservado.
        $balance = \App\Modules\Inventory\Models\StockBalance::query()
            ->where('warehouse_id', $from->id)
            ->where('product_id', $product->id)
            ->first();
        $this->assertEquals(0, (float) $balance->quantity_reserved);
    }
}
