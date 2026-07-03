<?php

namespace App\Modules\InventoryTransfers\Services;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidStockQuantityException;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Models\InventoryTransferItem;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryTransferService
{
    public function __construct(private readonly InventoryMovementService $inventory)
    {
    }

    public function create(User $user, array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($user, $data): InventoryTransfer {
            $fromWarehouse = Warehouse::query()->findOrFail($data['from_warehouse_id']);
            $toWarehouse = Warehouse::query()->findOrFail($data['to_warehouse_id']);

            $this->validateItems($fromWarehouse, $data['items']);

            $sequence = $this->nextSequence();
            $transfer = InventoryTransfer::create([
                'sequence' => $sequence,
                'document_number' => 'TRF-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'type' => $data['type'] ?? InventoryTransfer::TYPE_INTERNAL,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'status' => InventoryTransfer::STATUS_COMPLETED,
                'reason' => $data['reason'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'processed_at' => $data['processed_at'] ?? now(),
            ]);

            foreach ($data['items'] as $item) {
                $product = Product::query()->findOrFail($item['product_id']);
                $quantity = (float) $item['quantity'];

                try {
                    [$outMovement, $inMovement] = $this->inventory->transfer(
                        fromWarehouse: $fromWarehouse,
                        toWarehouse: $toWarehouse,
                        product: $product,
                        quantity: $quantity,
                        createdBy: $user,
                        reason: "Transferencia {$transfer->document_number}: {$transfer->reason}",
                        referenceType: InventoryTransfer::class,
                        referenceId: $transfer->id,
                    );
                } catch (InsufficientStockException|InvalidStockQuantityException $exception) {
                    throw ValidationException::withMessages([
                        'items' => $exception->getMessage(),
                    ]);
                }

                $unitIds = $item['product_unit_ids'] ?? [];

                InventoryTransferItem::create([
                    'inventory_transfer_id' => $transfer->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'out_stock_movement_id' => $outMovement->id,
                    'in_stock_movement_id' => $inMovement->id,
                    'product_unit_ids' => $unitIds ?: null,
                ]);

                $this->moveProductUnits($unitIds, $toWarehouse, $inMovement->id);
            }

            return $transfer->refresh()->load(['fromWarehouse', 'toWarehouse', 'items.product']);
        });
    }

    private function validateItems(Warehouse $fromWarehouse, array $items): void
    {
        $selectedUnitIds = [];

        foreach ($items as $index => $item) {
            $product = Product::query()->findOrFail($item['product_id']);
            $unitIds = $item['product_unit_ids'] ?? [];
            $quantity = (float) $item['quantity'];

            if ($product->requiresSerializedTracking()) {
                if ($quantity !== floor($quantity)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => 'Los productos serializados requieren cantidad entera.',
                    ]);
                }

                if (count($unitIds) !== (int) $quantity) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_unit_ids" => 'Debe indicar una unidad serializada disponible por cada cantidad de traslado.',
                    ]);
                }
            } elseif ($unitIds !== []) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_unit_ids" => 'Solo los productos serializados pueden trasladar unidades especificas.',
                ]);
            }

            foreach ($unitIds as $unitIndex => $unitId) {
                if (isset($selectedUnitIds[$unitId])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_unit_ids.{$unitIndex}" => 'No se puede repetir la misma unidad en un traslado.',
                    ]);
                }

                $selectedUnitIds[$unitId] = true;
                $unit = ProductUnit::query()->lockForUpdate()->find($unitId);

                if (! $unit
                    || (int) $unit->product_id !== (int) $item['product_id']
                    || (int) $unit->warehouse_id !== (int) $fromWarehouse->id
                    || $unit->status !== ProductUnit::STATUS_AVAILABLE) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_unit_ids.{$unitIndex}" => 'La unidad serializada no esta disponible en el almacen origen indicado.',
                    ]);
                }
            }
        }
    }

    private function moveProductUnits(array $unitIds, Warehouse $toWarehouse, int $movementId): void
    {
        if ($unitIds === []) {
            return;
        }

        ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->lockForUpdate()
            ->get()
            ->each(function (ProductUnit $unit) use ($toWarehouse, $movementId): void {
                $unit->update([
                    'warehouse_id' => $toWarehouse->id,
                    'acquired_stock_movement_id' => $movementId,
                    'released_stock_movement_id' => null,
                ]);
            });
    }

    private function nextSequence(): int
    {
        $lastSequence = InventoryTransfer::query()
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->value('sequence');

        return ((int) $lastSequence) + 1;
    }
}
