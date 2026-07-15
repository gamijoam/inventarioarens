<?php

namespace Tests\Feature\InventoryTransfers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Models\InventoryTransferDriver;
use App\Modules\InventoryTransfers\Services\InventoryTransferService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FASE T1: tests para el modelo InventoryTransferDriver y los endpoints
 * assignDriver / removeDriver. El transportista NO necesita user en
 * el sistema; solo se registran sus datos + opcional URL de firma.
 */
class InventoryTransferDriverApiTest extends TestCase
{
    use RefreshDatabase;

    private function setupTransfer(): array
    {
        $tenant = Tenant::create(['name' => 'Tienda Driver', 'slug' => 'tienda-driver']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-DRV']);
        $from = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen', 'code' => 'WH-DRV-ORIG']);
        $to = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino', 'code' => 'WH-DRV-DEST']);

        $product = \App\Modules\Products\Models\Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-DRV-'.uniqid(),
            'tracking_type' => \App\Modules\Products\Models\Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => \App\Modules\Products\Models\Product::CURRENCY_USD,
        ]);

        // Creamos un transfer logistics (status=requested) que NO mueve
        // stock al crear. Asi el test del driver no necesita stock previo.
        $service = app(InventoryTransferService::class);
        $transfer = $service->create($user, [
            'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'reason' => 'Test',
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]);

        return [$user, $transfer];
    }

    public function test_assign_driver_creates_new_record(): void
    {
        [$user, $transfer] = $this->setupTransfer();
        $service = app(InventoryTransferService::class);

        $driver = $service->assignDriver($user, $transfer, [
            'name' => 'Pedro Transportista',
            'document_number' => 'V-12345678',
            'phone' => '+58 412 1234567',
            'vehicle_plate' => 'ABC-123',
            'carrier_company' => 'Transportes XYZ',
        ]);

        $this->assertNotNull($driver->id);
        $this->assertSame('Pedro Transportista', $driver->name);
        $this->assertSame('V-12345678', $driver->document_number);
        $this->assertSame('ABC-123', $driver->vehicle_plate);
        $this->assertSame($transfer->id, $driver->inventory_transfer_id);
        $this->assertFalse($driver->isDriverSigned());
        $this->assertFalse($driver->isReceiverSigned());
    }

    public function test_assign_driver_updates_existing_record(): void
    {
        [$user, $transfer] = $this->setupTransfer();
        $service = app(InventoryTransferService::class);

        $first = $service->assignDriver($user, $transfer, ['name' => 'Pedro']);
        $second = $service->assignDriver($user, $transfer, ['name' => 'Juan', 'phone' => '+58 412 9999999']);

        $this->assertSame($first->id, $second->id, 'Debe actualizar el mismo registro, no crear uno nuevo');
        $this->assertSame('Juan', $second->name);
        $this->assertSame('+58 412 9999999', $second->phone);
    }

    public function test_driver_signed_flags_work(): void
    {
        [$user, $transfer] = $this->setupTransfer();
        $service = app(InventoryTransferService::class);

        $driver = $service->assignDriver($user, $transfer, [
            'name' => 'Pedro',
            'signed_by_driver_at' => now()->subHour(),
            'signature_driver_url' => 'https://storage.example.com/sig1.png',
            'signed_by_receiver_at' => now(),
            'signature_receiver_url' => 'https://storage.example.com/sig2.png',
        ]);

        $this->assertTrue($driver->isDriverSigned());
        $this->assertTrue($driver->isReceiverSigned());
    }

    public function test_remove_driver_deletes_record(): void
    {
        [$user, $transfer] = $this->setupTransfer();
        $service = app(InventoryTransferService::class);

        $service->assignDriver($user, $transfer, ['name' => 'Pedro']);
        $this->assertNotNull(InventoryTransferDriver::where('inventory_transfer_id', $transfer->id)->first());

        $service->removeDriver($user, $transfer);
        $this->assertNull(InventoryTransferDriver::where('inventory_transfer_id', $transfer->id)->first());
    }

    public function test_remove_driver_is_idempotent_when_no_driver(): void
    {
        [$user, $transfer] = $this->setupTransfer();
        $service = app(InventoryTransferService::class);

        // No debe lanzar excepcion si no hay driver.
        $service->removeDriver($user, $transfer);
        $this->assertNull(InventoryTransferDriver::where('inventory_transfer_id', $transfer->id)->first());
    }
}
