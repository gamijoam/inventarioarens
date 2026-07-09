<?php

namespace App\Modules\InventoryTransfers\Services;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidStockQuantityException;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Models\InventoryTransferChecklist;
use App\Modules\InventoryTransfers\Models\InventoryTransferChecklistItem;
use App\Modules\InventoryTransfers\Models\InventoryTransferGuide;
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
            $validationMode = $data['validation_mode'] ?? InventoryTransfer::VALIDATION_SIMPLE;
            $processedAt = $data['processed_at'] ?? now();

            if ($validationMode === InventoryTransfer::VALIDATION_LOGISTICS) {
                return $this->createLogisticTransfer($user, $data, $fromWarehouse, $toWarehouse, $sequence, $processedAt);
            }

            $transfer = InventoryTransfer::create([
                'sequence' => $sequence,
                'document_number' => 'TRF-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'guide_number' => 'GUIA-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'type' => $data['type'] ?? InventoryTransfer::TYPE_INTERNAL,
                'validation_mode' => InventoryTransfer::VALIDATION_SIMPLE,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'status' => InventoryTransfer::STATUS_COMPLETED,
                'reason' => $data['reason'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'processed_at' => $processedAt,
                'requested_at' => $processedAt,
                'prepared_at' => $processedAt,
                'dispatched_at' => $processedAt,
                'received_at' => $processedAt,
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
                    'requested_quantity' => $quantity,
                    'prepared_quantity' => $quantity,
                    'received_quantity' => $quantity,
                    'difference_quantity' => 0,
                    'out_stock_movement_id' => $outMovement->id,
                    'in_stock_movement_id' => $inMovement->id,
                    'product_unit_ids' => $unitIds ?: null,
                    'prepared_product_unit_ids' => $unitIds ?: null,
                    'received_product_unit_ids' => $unitIds ?: null,
                ]);

                $this->moveProductUnits($unitIds, $toWarehouse, $inMovement->id);
            }

            InventoryTransferGuide::create([
                'inventory_transfer_id' => $transfer->id,
                'guide_number' => $transfer->guide_number,
                'status' => InventoryTransferGuide::STATUS_COMPLETED,
                'issued_at' => $transfer->processed_at,
                'prepared_at' => $transfer->processed_at,
                'dispatched_at' => $transfer->processed_at,
                'received_at' => $transfer->processed_at,
                'issued_by' => $user->id,
                'prepared_by' => $user->id,
                'dispatched_by' => $user->id,
                'received_by' => $user->id,
                'metadata' => [
                    'mode' => InventoryTransfer::VALIDATION_SIMPLE,
                    'source' => 'inventory_transfer_service',
                ],
            ]);

            return $transfer->refresh()->load(['fromWarehouse', 'toWarehouse', 'guide.checklists.items', 'items.product']);
        });
    }

    public function prepare(User $user, InventoryTransfer $transfer, array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($user, $transfer, $data): InventoryTransfer {
            $transfer = InventoryTransfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->validation_mode !== InventoryTransfer::VALIDATION_LOGISTICS) {
                throw ValidationException::withMessages([
                    'transfer' => 'Solo los traslados logisticos requieren preparacion por checklist.',
                ]);
            }

            if ($transfer->status !== InventoryTransfer::STATUS_REQUESTED) {
                throw ValidationException::withMessages([
                    'transfer' => 'Solo se pueden preparar traslados solicitados.',
                ]);
            }

            $transfer->loadMissing(['fromWarehouse', 'guide.checklists.items', 'items.product']);

            $items = $transfer->items->keyBy('id');
            $payloadItems = collect($data['items']);
            $submittedItemIds = $payloadItems->pluck('inventory_transfer_item_id')->map(fn ($id): int => (int) $id)->all();
            $expectedItemIds = $items->keys()->map(fn ($id): int => (int) $id)->all();

            sort($submittedItemIds);
            sort($expectedItemIds);

            if ($submittedItemIds !== $expectedItemIds) {
                throw ValidationException::withMessages([
                    'items' => 'Debe preparar todos los productos incluidos en la guia.',
                ]);
            }

            $preparationChecklist = $transfer->guide?->checklists
                ->firstWhere('stage', InventoryTransferChecklist::STAGE_PREPARATION);

            if (! $preparationChecklist) {
                throw ValidationException::withMessages([
                    'transfer' => 'El traslado no tiene checklist de preparacion.',
                ]);
            }

            $checklistItems = $preparationChecklist->items->keyBy('inventory_transfer_item_id');
            $hasDifferences = false;

            foreach ($payloadItems as $index => $payloadItem) {
                /** @var InventoryTransferItem $item */
                $item = $items->get((int) $payloadItem['inventory_transfer_item_id']);
                $product = $item->product;
                $requestedQuantity = (float) ($item->requested_quantity ?? $item->quantity);
                $preparedUnitIds = $payloadItem['prepared_product_unit_ids'] ?? [];

                if ($product->requiresSerializedTracking()) {
                    $preparedQuantity = count($preparedUnitIds);
                    $this->validatePreparedProductUnits($transfer, $item, $preparedUnitIds, $index);
                } else {
                    if ($preparedUnitIds !== []) {
                        throw ValidationException::withMessages([
                            "items.{$index}.prepared_product_unit_ids" => 'Solo los productos serializados pueden preparar IMEIs o seriales especificos.',
                        ]);
                    }

                    $preparedQuantity = (float) ($payloadItem['prepared_quantity'] ?? $requestedQuantity);
                }

                if ($preparedQuantity > $requestedQuantity) {
                    throw ValidationException::withMessages([
                        "items.{$index}.prepared_quantity" => 'La cantidad preparada no puede superar la cantidad solicitada.',
                    ]);
                }

                $differenceQuantity = $requestedQuantity - $preparedQuantity;
                $differenceReason = $payloadItem['difference_reason'] ?? null;
                $differenceNotes = $payloadItem['difference_notes'] ?? null;

                if ($differenceQuantity > 0 && blank($differenceReason)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.difference_reason" => 'Debe indicar el motivo cuando se prepara menos de lo solicitado.',
                    ]);
                }

                if ($preparedQuantity > 0) {
                    $movement = $this->inventory->reserve(
                        warehouse: $transfer->fromWarehouse,
                        product: $product,
                        quantity: $preparedQuantity,
                        createdBy: $user,
                        reason: "Preparacion {$transfer->guide_number}: {$transfer->reason}",
                        referenceType: InventoryTransfer::class,
                        referenceId: $transfer->id,
                    );

                    $this->markPreparedProductUnitsAsReserved($preparedUnitIds, $movement->id);
                }

                $item->update([
                    'prepared_quantity' => $preparedQuantity,
                    'difference_quantity' => $differenceQuantity,
                    'difference_reason' => $differenceReason,
                    'difference_notes' => $differenceNotes,
                    'prepared_product_unit_ids' => $preparedUnitIds ?: null,
                ]);

                $checklistItems->get($item->id)?->update([
                    'checked_quantity' => $preparedQuantity,
                    'difference_quantity' => $differenceQuantity,
                    'reason' => $differenceReason,
                    'notes' => $differenceNotes,
                    'checked_product_unit_ids' => $preparedUnitIds ?: null,
                ]);

                $hasDifferences = $hasDifferences || $differenceQuantity > 0;
            }

            $preparedAt = $data['prepared_at'] ?? now();
            $transferStatus = $hasDifferences
                ? InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES
                : InventoryTransfer::STATUS_PREPARED;
            $guideStatus = $hasDifferences
                ? InventoryTransferGuide::STATUS_PREPARED_WITH_DIFFERENCES
                : InventoryTransferGuide::STATUS_PREPARED;
            $checklistStatus = $hasDifferences
                ? InventoryTransferChecklist::STATUS_COMPLETED_WITH_DIFFERENCES
                : InventoryTransferChecklist::STATUS_COMPLETED;

            $preparationChecklist->update([
                'status' => $checklistStatus,
                'completed_by' => $user->id,
                'completed_at' => $preparedAt,
                'notes' => $data['notes'] ?? null,
            ]);

            $transfer->guide?->update([
                'status' => $guideStatus,
                'prepared_at' => $preparedAt,
                'prepared_by' => $user->id,
            ]);

            $transfer->update([
                'status' => $transferStatus,
                'prepared_at' => $preparedAt,
                'prepared_by' => $user->id,
            ]);

            return $transfer->refresh()->load(['fromWarehouse', 'toWarehouse', 'guide.checklists.items', 'items.product']);
        });
    }

    private function createLogisticTransfer(
        User $user,
        array $data,
        Warehouse $fromWarehouse,
        Warehouse $toWarehouse,
        int $sequence,
        mixed $requestedAt,
    ): InventoryTransfer {
        $transfer = InventoryTransfer::create([
            'sequence' => $sequence,
            'document_number' => 'TRF-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'guide_number' => 'GUIA-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'type' => $data['type'] ?? InventoryTransfer::TYPE_INTERNAL,
            'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
            'from_warehouse_id' => $fromWarehouse->id,
            'to_warehouse_id' => $toWarehouse->id,
            'status' => InventoryTransfer::STATUS_REQUESTED,
            'reason' => $data['reason'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
            'requested_at' => $requestedAt,
        ]);

        foreach ($data['items'] as $item) {
            $product = Product::query()->findOrFail($item['product_id']);
            $quantity = (float) $item['quantity'];
            $unitIds = $item['product_unit_ids'] ?? [];

            InventoryTransferItem::create([
                'inventory_transfer_id' => $transfer->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'requested_quantity' => $quantity,
                'prepared_quantity' => 0,
                'received_quantity' => 0,
                'difference_quantity' => 0,
                'product_unit_ids' => $unitIds ?: null,
            ]);
        }

        $guide = InventoryTransferGuide::create([
            'inventory_transfer_id' => $transfer->id,
            'guide_number' => $transfer->guide_number,
            'status' => InventoryTransferGuide::STATUS_GENERATED,
            'issued_at' => $requestedAt,
            'issued_by' => $user->id,
            'metadata' => [
                'mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'source' => 'inventory_transfer_service',
                'stock_moved' => false,
            ],
        ]);

        $preparationChecklist = InventoryTransferChecklist::create([
            'inventory_transfer_id' => $transfer->id,
            'inventory_transfer_guide_id' => $guide->id,
            'stage' => InventoryTransferChecklist::STAGE_PREPARATION,
            'status' => InventoryTransferChecklist::STATUS_PENDING,
        ]);

        $transfer->items()->get()->each(function (InventoryTransferItem $item) use ($preparationChecklist): void {
            InventoryTransferChecklistItem::create([
                'inventory_transfer_checklist_id' => $preparationChecklist->id,
                'inventory_transfer_item_id' => $item->id,
                'product_id' => $item->product_id,
                'expected_quantity' => $item->requested_quantity ?? $item->quantity,
                'checked_quantity' => 0,
                'difference_quantity' => 0,
                'expected_product_unit_ids' => $item->product_unit_ids,
            ]);
        });

        return $transfer->refresh()->load(['fromWarehouse', 'toWarehouse', 'guide.checklists.items', 'items.product']);
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

    private function validatePreparedProductUnits(
        InventoryTransfer $transfer,
        InventoryTransferItem $item,
        array $unitIds,
        int $itemIndex,
    ): void {
        if ((float) ($item->requested_quantity ?? $item->quantity) !== floor((float) ($item->requested_quantity ?? $item->quantity))) {
            throw ValidationException::withMessages([
                "items.{$itemIndex}.prepared_product_unit_ids" => 'Los productos serializados requieren cantidad entera.',
            ]);
        }

        if (count($unitIds) !== count(array_unique($unitIds))) {
            throw ValidationException::withMessages([
                "items.{$itemIndex}.prepared_product_unit_ids" => 'No se puede repetir el mismo IMEI o serial en la preparacion.',
            ]);
        }

        $expectedUnitIds = $item->product_unit_ids ?? [];

        if ($expectedUnitIds !== [] && array_diff($unitIds, $expectedUnitIds) !== []) {
            throw ValidationException::withMessages([
                "items.{$itemIndex}.prepared_product_unit_ids" => 'Solo se pueden preparar IMEIs o seriales incluidos en la guia.',
            ]);
        }

        if ($unitIds === []) {
            return;
        }

        $units = ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($units->count() !== count($unitIds)) {
            throw ValidationException::withMessages([
                "items.{$itemIndex}.prepared_product_unit_ids" => 'Uno o mas IMEIs o seriales no existen.',
            ]);
        }

        foreach ($unitIds as $unitIndex => $unitId) {
            $unit = $units->get($unitId);

            if ((int) $unit->product_id !== (int) $item->product_id
                || (int) $unit->warehouse_id !== (int) $transfer->from_warehouse_id
                || $unit->status !== ProductUnit::STATUS_AVAILABLE) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.prepared_product_unit_ids.{$unitIndex}" => 'El IMEI o serial no esta disponible en el almacen origen.',
                ]);
            }
        }
    }

    private function markPreparedProductUnitsAsReserved(array $unitIds, int $movementId): void
    {
        if ($unitIds === []) {
            return;
        }

        ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->lockForUpdate()
            ->get()
            ->each(function (ProductUnit $unit) use ($movementId): void {
                $unit->update([
                    'status' => ProductUnit::STATUS_RESERVED,
                    'released_stock_movement_id' => $movementId,
                ]);
            });
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
