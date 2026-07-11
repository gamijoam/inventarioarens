<?php

namespace Tests\Feature\InventoryTransfers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\InventoryTransfers\Services\InventoryTransferService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryTransferReceiveImeiCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_serialized_receive_with_matching_imei_count_succeeds(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda IMEI OK', 'slug' => 'tienda-imei-ok']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->useTenant($tenant);

        [$transfer, $item, $unit1, $unit2, $unit3] = $this->createDispatchedSerializedTransfer($user, quantity: 3);

        $service = app(InventoryTransferService::class);
        $service->receive($user, $transfer->fresh(), [
            'items' => [
                [
                    'inventory_transfer_item_id' => $item->id,
                    'received_quantity' => 3,
                    'received_product_unit_ids' => [$unit1->id, $unit2->id, $unit3->id],
                ],
            ],
        ]);

        $item->refresh();
        $this->assertSame(3.0, (float) $item->received_quantity);
        $this->assertNotNull($item->in_stock_movement_id, 'Debe crearse transfer_in movement');

        $this->assertDatabaseHas('product_units', [
            'id' => $unit1->id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $this->assertDatabaseHas('product_units', [
            'id' => $unit2->id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $this->assertDatabaseHas('product_units', [
            'id' => $unit3->id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
    }

    public function test_serialized_receive_with_empty_imeis_but_nonzero_quantity_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda IMEI Vacio', 'slug' => 'tienda-imei-vacio']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->useTenant($tenant);

        [$transfer, $item] = $this->createDispatchedSerializedTransfer($user, quantity: 3, returnUnits: false);

        $service = app(InventoryTransferService::class);

        $threw = false;
        try {
            $service->receive($user, $transfer->fresh(), [
                'items' => [
                    [
                        'inventory_transfer_item_id' => $item->id,
                        'received_quantity' => 3,
                        'received_product_unit_ids' => [],
                    ],
                ],
            ]);
        } catch (ValidationException $exception) {
            $threw = true;
            $errors = $exception->errors();
            $this->assertArrayHasKey('items.0.received_product_unit_ids', $errors);
            $this->assertStringContainsString('IMEIs', $errors['items.0.received_product_unit_ids'][0]);
        }

        $this->assertTrue($threw, 'Debe lanzar ValidationException cuando se intenta recibir 3 unidades sin IMEIs');

        $movementCount = StockMovement::query()
            ->where('reference_type', \App\Modules\InventoryTransfers\Models\InventoryTransfer::class)
            ->where('reference_id', $transfer->id)
            ->where('type', 'transfer_in')
            ->count();
        $this->assertSame(0, $movementCount, 'NO debe crearse transfer_in movement');
    }

    public function test_serialized_receive_with_mismatched_imei_count_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda IMEI Mismatch', 'slug' => 'tienda-imei-mismatch']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->useTenant($tenant);

        [$transfer, $item, $unit1, $unit2, $unit3] = $this->createDispatchedSerializedTransfer($user, quantity: 3);

        $service = app(InventoryTransferService::class);

        $threw = false;
        try {
            $service->receive($user, $transfer->fresh(), [
                'items' => [
                    [
                        'inventory_transfer_item_id' => $item->id,
                        'received_quantity' => 3,
                        'received_product_unit_ids' => [$unit1->id, $unit2->id],
                    ],
                ],
            ]);
        } catch (ValidationException $exception) {
            $threw = true;
            $errors = $exception->errors();
            $this->assertArrayHasKey('items.0.received_product_unit_ids', $errors);
        }

        $this->assertTrue($threw, 'Debe lanzar ValidationException cuando count(unit_ids)=2 != received_quantity=3');
    }

    public function test_serialized_receive_with_empty_imeis_and_zero_quantity_succeeds(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda IMEI Cero', 'slug' => 'tienda-imei-cero']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->useTenant($tenant);

        [$transfer, $item] = $this->createDispatchedSerializedTransfer($user, quantity: 3, returnUnits: false);

        $service = app(InventoryTransferService::class);

        $service->receive($user, $transfer->fresh(), [
            'items' => [
                [
                    'inventory_transfer_item_id' => $item->id,
                    'received_quantity' => 0,
                    'received_product_unit_ids' => [],
                    'difference_reason' => 'Mercancia no llego',
                ],
            ],
        ]);

        $item->refresh();
        $this->assertSame(0.0, (float) $item->received_quantity);
        $this->assertSame(3.0, (float) $item->difference_quantity);
        $this->assertNull($item->in_stock_movement_id, 'NO debe haber transfer_in movement con quantity 0');
    }

    public function test_non_serialized_receive_with_imeis_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda No Serial', 'slug' => 'tienda-no-serial']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->useTenant($tenant);

        [$transfer, $item] = $this->createDispatchedQuantityTransfer($user, quantity: 3);

        $service = app(InventoryTransferService::class);

        $threw = false;
        try {
            $service->receive($user, $transfer->fresh(), [
                'items' => [
                    [
                        'inventory_transfer_item_id' => $item->id,
                        'received_quantity' => 3,
                        'received_product_unit_ids' => [999, 1000],
                    ],
                ],
            ]);
        } catch (ValidationException $exception) {
            $threw = true;
            $errors = $exception->errors();
            $this->assertArrayHasKey('items.0.received_product_unit_ids', $errors);
            $this->assertStringContainsString('serializados', $errors['items.0.received_product_unit_ids'][0]);
        }

        $this->assertTrue($threw, 'Debe rechazar unit_ids en producto no serializado');
    }

    private function createDispatchedSerializedTransfer(User $user, int $quantity, bool $returnUnits = true): array
    {
        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-IMEI']);
        $fromWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen', 'code' => 'WH-IMEI-ORIG']);
        $toWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino', 'code' => 'WH-IMEI-DEST']);

        $product = Product::create([
            'name' => 'Producto Serializado',
            'sku' => 'PROD-SERIAL-IMEI',
            'tracking_type' => Product::TRACKING_SERIALIZED,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        app(InventoryMovementService::class)->purchase($fromWarehouse, $product, $quantity + 2, 50, $user, 'Stock inicial');

        $units = [];
        for ($i = 1; $i <= $quantity; $i++) {
            $unit = ProductUnit::create([
                'product_id' => $product->id,
                'warehouse_id' => $fromWarehouse->id,
                'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                'serial_number' => sprintf('IMEI-%03d-%s', $i, uniqid()),
                'status' => ProductUnit::STATUS_AVAILABLE,
            ]);
            $units[] = $unit;
        }

        $unitIds = array_map(fn ($u) => $u->id, $units);

        $transfer = app(InventoryTransferService::class)->create($user, [
            'validation_mode' => \App\Modules\InventoryTransfers\Models\InventoryTransfer::VALIDATION_LOGISTICS,
            'from_warehouse_id' => $fromWarehouse->id,
            'to_warehouse_id' => $toWarehouse->id,
            'reason' => 'Traslado de prueba IMEI',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'product_unit_ids' => $unitIds,
            ]],
        ]);

        $item = $transfer->items()->firstOrFail();

        app(InventoryTransferService::class)->prepare($user, $transfer->fresh(), [
            'items' => [[
                'inventory_transfer_item_id' => $item->id,
                'prepared_quantity' => $quantity,
                'prepared_product_unit_ids' => $unitIds,
            ]],
        ]);

        app(InventoryTransferService::class)->dispatch($user, $transfer->fresh(), []);

        $item->refresh();

        if (! $returnUnits) {
            return [$transfer, $item];
        }

        return [
            $transfer,
            $item,
            ...$units,
        ];
    }

    private function createDispatchedQuantityTransfer(User $user, int $quantity): array
    {
        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-QTY']);
        $fromWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen', 'code' => 'WH-QTY-ORIG']);
        $toWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino', 'code' => 'WH-QTY-DEST']);

        $product = Product::create([
            'name' => 'Producto Cantidad',
            'sku' => 'PROD-QTY-IMEI',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 50,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        app(InventoryMovementService::class)->purchase($fromWarehouse, $product, $quantity + 2, 25, $user, 'Stock inicial QTY');

        $transfer = app(InventoryTransferService::class)->create($user, [
            'validation_mode' => \App\Modules\InventoryTransfers\Models\InventoryTransfer::VALIDATION_LOGISTICS,
            'from_warehouse_id' => $fromWarehouse->id,
            'to_warehouse_id' => $toWarehouse->id,
            'reason' => 'Traslado de prueba cantidad',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]],
        ]);

        $item = $transfer->items()->firstOrFail();

        app(InventoryTransferService::class)->prepare($user, $transfer->fresh(), [
            'items' => [[
                'inventory_transfer_item_id' => $item->id,
                'prepared_quantity' => $quantity,
                'prepared_product_unit_ids' => [],
            ]],
        ]);

        app(InventoryTransferService::class)->dispatch($user, $transfer->fresh(), []);

        $item->refresh();

        return [$transfer, $item];
    }

    private function useTenant(Tenant $tenant): void
    {
        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}