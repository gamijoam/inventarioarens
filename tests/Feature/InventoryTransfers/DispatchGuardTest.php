<?php

namespace Tests\Feature\InventoryTransfers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Services\InventoryTransferService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DispatchGuardTest extends TestCase
{
    use RefreshDatabase;

    private function setupTransferWithItems(float $preparedQuantity, int $itemCount = 1): InventoryTransfer
    {
        $tenant = Tenant::create(['name' => 'Tienda Dispatch Guard', 'slug' => 'tienda-dispatch-guard']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-DG']);
        $from = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen', 'code' => 'WH-DG-ORIG']);
        $to = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino', 'code' => 'WH-DG-DEST']);

        $product = \App\Modules\Products\Models\Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-DG-'.uniqid(),
            'tracking_type' => \App\Modules\Products\Models\Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => \App\Modules\Products\Models\Product::CURRENCY_USD,
        ]);

        app(InventoryMovementService::class)->purchase($from, $product, 10, 5, $user, 'Stock inicial');

        $items = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $items[] = [
                'product_id' => $product->id,
                'quantity' => 5,
            ];
        }

        $service = app(InventoryTransferService::class);
        $transfer = $service->create($user, [
            'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'reason' => 'Test',
            'items' => $items,
        ]);

        $preparedItems = [];
        foreach ($transfer->items as $item) {
            $entry = [
                'inventory_transfer_item_id' => $item->id,
                'prepared_quantity' => $preparedQuantity,
            ];
            if ($preparedQuantity < (float) $item->quantity) {
                $entry['difference_reason'] = 'Test difference';
            }
            $preparedItems[] = $entry;
        }

        $service->prepare($user, $transfer, ['items' => $preparedItems]);

        return $transfer->fresh();
    }

    public function test_dispatch_rejected_when_all_items_have_prepared_quantity_zero(): void
    {
        $transfer = $this->setupTransferWithItems(preparedQuantity: 0);

        $service = app(InventoryTransferService::class);
        $user = $transfer->created_by_user ?? User::query()->first();

        $threw = false;
        try {
            $service->dispatch($user, $transfer, []);
        } catch (ValidationException $exception) {
            $threw = true;
            $this->assertSame(
                'No se puede despachar: ningun item tiene cantidad preparada mayor a cero.',
                $exception->errors()['items'][0]
            );
        }

        $this->assertTrue($threw, 'dispatch() debe lanzar ValidationException cuando todos los items tienen prepared_quantity=0');

        $transfer->refresh();
        $this->assertContains($transfer->status, [
            InventoryTransfer::STATUS_PREPARED,
            InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES,
        ], 'Transfer NO debe cambiar a DISPATCHED cuando todos los items tienen 0 preparado');

        $this->assertNull($transfer->dispatched_at, 'dispatched_at NO debe setearse');
    }

    public function test_dispatch_rejected_when_multiple_items_all_have_zero(): void
    {
        $transfer = $this->setupTransferWithItems(preparedQuantity: 0, itemCount: 3);

        $service = app(InventoryTransferService::class);
        $user = User::query()->first();

        $threw = false;
        try {
            $service->dispatch($user, $transfer, []);
        } catch (ValidationException $exception) {
            $threw = true;
        }

        $this->assertTrue($threw, 'dispatch() debe rechazar incluso con múltiples items todos en 0');

        $transfer->refresh();
        $this->assertContains($transfer->status, [
            InventoryTransfer::STATUS_PREPARED,
            InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES,
        ], 'Status debe permanecer en estado preparado, no DISPATCHED');
    }

    public function test_dispatch_proceeds_when_at_least_one_item_has_prepared_quantity_greater_than_zero(): void
    {
        $transfer = $this->setupTransferWithItems(preparedQuantity: 3);

        $service = app(InventoryTransferService::class);
        $user = User::query()->first();

        $service->dispatch($user, $transfer, []);

        $transfer->refresh();
        $this->assertSame(
            InventoryTransfer::STATUS_DISPATCHED,
            $transfer->status,
            'Transfer debe pasar a DISPATCHED si al menos un item tiene prepared_quantity > 0'
        );
    }

    public function test_dispatch_succeeds_with_mixed_zero_and_positive_quantities(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Mixed', 'slug' => 'tienda-mixed']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-MX']);
        $from = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen', 'code' => 'WH-MX-ORIG']);
        $to = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino', 'code' => 'WH-MX-DEST']);

        $product = \App\Modules\Products\Models\Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-MX-'.uniqid(),
            'tracking_type' => \App\Modules\Products\Models\Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => \App\Modules\Products\Models\Product::CURRENCY_USD,
        ]);

        app(InventoryMovementService::class)->purchase($from, $product, 10, 5, $user, 'Stock inicial');

        $service = app(InventoryTransferService::class);
        $transfer = $service->create($user, [
            'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'reason' => 'Test',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5],
                ['product_id' => $product->id, 'quantity' => 5],
                ['product_id' => $product->id, 'quantity' => 5],
            ],
        ]);

        $service->prepare($user, $transfer, [
            'items' => [
                ['inventory_transfer_item_id' => $transfer->items[0]->id, 'prepared_quantity' => 0, 'difference_reason' => 'No listo'],
                ['inventory_transfer_item_id' => $transfer->items[1]->id, 'prepared_quantity' => 5],
                ['inventory_transfer_item_id' => $transfer->items[2]->id, 'prepared_quantity' => 0, 'difference_reason' => 'No listo'],
            ],
        ]);

        $service->dispatch($user, $transfer->fresh(), []);

        $transfer->refresh();
        $this->assertSame(InventoryTransfer::STATUS_DISPATCHED, $transfer->status, 'Si al menos un item tiene prepared > 0, dispatch procede');
    }
}
