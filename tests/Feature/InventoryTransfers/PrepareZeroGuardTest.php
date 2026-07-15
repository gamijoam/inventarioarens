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

/**
 * FASE T1 fix (audit H2): bloquea prepare() con todos los items en 0.
 * Antes del fix, prepare aceptaba todo en 0 y avanzaba a status
 * 'prepared_with_differences' sin reservar nada. Ahora lanza 422 con
 * un mensaje claro.
 */
class PrepareZeroGuardTest extends TestCase
{
    use RefreshDatabase;

    private function setupLogisticTransfer(int $itemCount = 1): array
    {
        $tenant = Tenant::create(['name' => 'Tienda Prepare Guard', 'slug' => 'tienda-prepare-guard']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-PG']);
        $from = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen', 'code' => 'WH-PG-ORIG']);
        $to = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino', 'code' => 'WH-PG-DEST']);

        $product = \App\Modules\Products\Models\Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-PG-'.uniqid(),
            'tracking_type' => \App\Modules\Products\Models\Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => \App\Modules\Products\Models\Product::CURRENCY_USD,
        ]);

        app(InventoryMovementService::class)->purchase($from, $product, 10, 5, $user, 'Stock inicial');

        $items = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $items[] = ['product_id' => $product->id, 'quantity' => 5];
        }

        $service = app(InventoryTransferService::class);
        $transfer = $service->create($user, [
            'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'reason' => 'Test',
            'items' => $items,
        ]);

        return [$user, $transfer];
    }

    public function test_prepare_rejected_when_single_item_has_zero_prepared_quantity(): void
    {
        [$user, $transfer] = $this->setupLogisticTransfer(itemCount: 1);
        $service = app(InventoryTransferService::class);

        $threw = false;
        $message = null;
        try {
            $service->prepare($user, $transfer, [
                'items' => [
                    [
                        'inventory_transfer_item_id' => $transfer->items[0]->id,
                        'prepared_quantity' => 0,
                    ],
                ],
            ]);
        } catch (ValidationException $exception) {
            $threw = true;
            $message = $exception->errors()['transfer'][0] ?? null;
        }

        $this->assertTrue($threw, 'prepare() debe lanzar ValidationException con todo en 0');
        $this->assertSame(
            'No se puede preparar: al menos un item debe tener cantidad preparada mayor a cero.',
            $message,
        );

        $transfer->refresh();
        $this->assertSame(InventoryTransfer::STATUS_REQUESTED, $transfer->status);
    }

    public function test_prepare_rejected_when_multiple_items_all_have_zero(): void
    {
        [$user, $transfer] = $this->setupLogisticTransfer(itemCount: 3);
        $service = app(InventoryTransferService::class);

        $threw = false;
        try {
            $service->prepare($user, $transfer, [
                'items' => array_map(fn ($item) => [
                    'inventory_transfer_item_id' => $item->id,
                    'prepared_quantity' => 0,
                ], $transfer->items->all()),
            ]);
        } catch (ValidationException $exception) {
            $threw = true;
        }

        $this->assertTrue($threw, 'prepare() debe rechazar con multiples items todos en 0');
        $transfer->refresh();
        $this->assertSame(InventoryTransfer::STATUS_REQUESTED, $transfer->status);
    }

    public function test_prepare_proceeds_with_partial_zero_when_at_least_one_item_positive(): void
    {
        [$user, $transfer] = $this->setupLogisticTransfer(itemCount: 3);
        $service = app(InventoryTransferService::class);

        // 2 items en 0, 1 item en 5. Debe proceder con prepared_with_differences.
        $service->prepare($user, $transfer, [
            'items' => [
                [
                    'inventory_transfer_item_id' => $transfer->items[0]->id,
                    'prepared_quantity' => 0,
                    'difference_reason' => 'Sin stock disponible',
                ],
                [
                    'inventory_transfer_item_id' => $transfer->items[1]->id,
                    'prepared_quantity' => 5,
                ],
                [
                    'inventory_transfer_item_id' => $transfer->items[2]->id,
                    'prepared_quantity' => 0,
                    'difference_reason' => 'Sin stock disponible',
                ],
            ],
        ]);

        $transfer->refresh();
        $this->assertSame(
            InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES,
            $transfer->status,
            'Si al menos un item tiene prepared > 0, el transfer avanza a prepared_with_differences',
        );
    }
}
