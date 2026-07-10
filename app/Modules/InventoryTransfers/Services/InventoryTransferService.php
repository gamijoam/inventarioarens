<?php

namespace App\Modules\InventoryTransfers\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
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
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryTransferService
{
    public function __construct(
        private readonly InventoryMovementService $inventory,
        private readonly SyncCatalogOutboxService $syncCatalog,
        private readonly AuditLogger $audit,
    )
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

                $this->syncCatalog->stockMovementCreated($outMovement);
                $this->syncCatalog->stockMovementCreated($inMovement);

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

            $this->audit->record(
                action: 'inventory_transfer.created',
                entity: $transfer,
                user: $user,
                newValues: [
                    'validation_mode' => $transfer->validation_mode,
                    'status' => $transfer->status,
                    'from_warehouse_id' => $transfer->from_warehouse_id,
                    'to_warehouse_id' => $transfer->to_warehouse_id,
                    'items_count' => count($data['items']),
                ],
            );

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

                    $this->syncCatalog->stockMovementCreated($movement);
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

            $this->audit->record(
                action: 'inventory_transfer.prepared',
                entity: $transfer,
                user: $user,
                newValues: [
                    'status' => $transferStatus,
                    'prepared_at' => $preparedAt->format('c'),
                    'has_differences' => $hasDifferences,
                    'items_count' => count($items),
                ],
            );

            return $transfer->refresh()->load(['fromWarehouse', 'toWarehouse', 'guide.checklists.items', 'items.product']);
        });
    }

    public function dispatch(User $user, InventoryTransfer $transfer, array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($user, $transfer, $data): InventoryTransfer {
            $transfer = InventoryTransfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->validation_mode !== InventoryTransfer::VALIDATION_LOGISTICS) {
                throw ValidationException::withMessages([
                    'transfer' => 'Solo los traslados logisticos requieren despacho por guia.',
                ]);
            }

            if (! in_array($transfer->status, [
                InventoryTransfer::STATUS_PREPARED,
                InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES,
            ], true)) {
                throw ValidationException::withMessages([
                    'transfer' => 'Solo se pueden despachar traslados preparados.',
                ]);
            }

            $transfer->loadMissing(['fromWarehouse', 'guide', 'items.product']);

            foreach ($transfer->items as $item) {
                /** @var InventoryTransferItem $item */
                $product = $item->product;
                $preparedQuantity = (float) ($item->prepared_quantity ?? 0);

                if ($preparedQuantity <= 0) {
                    continue;
                }

                $movement = $this->inventory->dispatchReservedTransfer(
                    warehouse: $transfer->fromWarehouse,
                    product: $product,
                    quantity: $preparedQuantity,
                    createdBy: $user,
                    reason: "Despacho {$transfer->guide_number}: {$transfer->reason}",
                    referenceType: InventoryTransfer::class,
                    referenceId: $transfer->id,
                );

                $this->syncCatalog->stockMovementCreated($movement);

                $item->update([
                    'out_stock_movement_id' => $movement->id,
                ]);

                $this->markDispatchedProductUnits($item->prepared_product_unit_ids ?? [], $movement->id);
            }

            $receptionChecklist = $this->ensureReceptionChecklist($transfer);
            $dispatchedAt = $data['dispatched_at'] ?? now();

            $transfer->guide?->update([
                'status' => InventoryTransferGuide::STATUS_DISPATCHED,
                'dispatched_at' => $dispatchedAt,
                'dispatched_by' => $user->id,
            ]);

            $transfer->update([
                'status' => InventoryTransfer::STATUS_DISPATCHED,
                'dispatched_at' => $dispatchedAt,
                'dispatched_by' => $user->id,
                'notes' => $data['notes'] ?? $transfer->notes,
            ]);

            $receptionChecklist->refresh();

            $this->audit->record(
                action: 'inventory_transfer.dispatched',
                entity: $transfer,
                user: $user,
                newValues: [
                    'status' => InventoryTransfer::STATUS_DISPATCHED,
                    'dispatched_at' => $dispatchedAt->format('c'),
                ],
            );

            return $transfer->refresh()->load(['fromWarehouse', 'toWarehouse', 'guide.checklists.items', 'items.product']);
        });
    }

    public function receive(User $user, InventoryTransfer $transfer, array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($user, $transfer, $data): InventoryTransfer {
            $transfer = InventoryTransfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->validation_mode !== InventoryTransfer::VALIDATION_LOGISTICS) {
                throw ValidationException::withMessages([
                    'transfer' => 'Solo los traslados logisticos requieren recepcion por guia.',
                ]);
            }

            if ($transfer->status !== InventoryTransfer::STATUS_DISPATCHED) {
                throw ValidationException::withMessages([
                    'transfer' => 'Solo se pueden recibir traslados despachados.',
                ]);
            }

            $transfer->loadMissing(['fromWarehouse', 'toWarehouse', 'guide.checklists.items', 'items.product']);

            $items = $transfer->items->keyBy('id');
            $payloadItems = collect($data['items']);
            $submittedItemIds = $payloadItems->pluck('inventory_transfer_item_id')->map(fn ($id): int => (int) $id)->all();
            $expectedItemIds = $items->keys()->map(fn ($id): int => (int) $id)->all();

            sort($submittedItemIds);
            sort($expectedItemIds);

            if ($submittedItemIds !== $expectedItemIds) {
                throw ValidationException::withMessages([
                    'items' => 'Debe recibir todos los productos incluidos en la guia.',
                ]);
            }

            $receptionChecklist = $this->ensureReceptionChecklist($transfer);
            $checklistItems = $receptionChecklist->items()->get()->keyBy('inventory_transfer_item_id');
            $hasDifferences = false;

            foreach ($payloadItems as $index => $payloadItem) {
                /** @var InventoryTransferItem $item */
                $item = $items->get((int) $payloadItem['inventory_transfer_item_id']);
                $product = $item->product;
                $expectedQuantity = (float) ($item->prepared_quantity ?? 0);
                $receivedUnitIds = $payloadItem['received_product_unit_ids'] ?? [];

                if ($product->requiresSerializedTracking()) {
                    $receivedQuantity = count($receivedUnitIds);
                    $this->validateReceivedProductUnits($transfer, $item, $receivedUnitIds, $index);
                } else {
                    if ($receivedUnitIds !== []) {
                        throw ValidationException::withMessages([
                            "items.{$index}.received_product_unit_ids" => 'Solo los productos serializados pueden recibir IMEIs o seriales especificos.',
                        ]);
                    }

                    $receivedQuantity = (float) ($payloadItem['received_quantity'] ?? $expectedQuantity);
                }

                if ($receivedQuantity > $expectedQuantity) {
                    throw ValidationException::withMessages([
                        "items.{$index}.received_quantity" => 'La cantidad recibida no puede superar la cantidad despachada.',
                    ]);
                }

                $differenceQuantity = $expectedQuantity - $receivedQuantity;
                $differenceReason = $payloadItem['difference_reason'] ?? null;
                $differenceNotes = $payloadItem['difference_notes'] ?? null;

                if ($differenceQuantity > 0 && blank($differenceReason)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.difference_reason" => 'Debe indicar el motivo cuando se recibe menos de lo despachado.',
                    ]);
                }

                $movementId = null;

                if ($receivedQuantity > 0) {
                    $movement = $this->inventory->receiveTransfer(
                        warehouse: $transfer->toWarehouse,
                        product: $product,
                        quantity: $receivedQuantity,
                        createdBy: $user,
                        reason: "Recepcion {$transfer->guide_number}: {$transfer->reason}",
                        referenceType: InventoryTransfer::class,
                        referenceId: $transfer->id,
                    );

                    $movementId = $movement->id;
                    $this->syncCatalog->stockMovementCreated($movement);
                    $this->moveProductUnits($receivedUnitIds, $transfer->toWarehouse, $movement->id);
                }

                $item->update([
                    'received_quantity' => $receivedQuantity,
                    'difference_quantity' => $differenceQuantity,
                    'difference_reason' => $differenceReason,
                    'difference_notes' => $differenceNotes,
                    'received_product_unit_ids' => $receivedUnitIds ?: null,
                    'in_stock_movement_id' => $movementId ?? $item->in_stock_movement_id,
                ]);

                $checklistItems->get($item->id)?->update([
                    'checked_quantity' => $receivedQuantity,
                    'difference_quantity' => $differenceQuantity,
                    'reason' => $differenceReason,
                    'notes' => $differenceNotes,
                    'checked_product_unit_ids' => $receivedUnitIds ?: null,
                ]);

                $hasDifferences = $hasDifferences || $differenceQuantity > 0;
            }

            $receivedAt = $data['received_at'] ?? now();
            $transferStatus = $hasDifferences
                ? InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES
                : InventoryTransfer::STATUS_COMPLETED;
            $guideStatus = $hasDifferences
                ? InventoryTransferGuide::STATUS_COMPLETED_WITH_DIFFERENCES
                : InventoryTransferGuide::STATUS_COMPLETED;
            $checklistStatus = $hasDifferences
                ? InventoryTransferChecklist::STATUS_COMPLETED_WITH_DIFFERENCES
                : InventoryTransferChecklist::STATUS_COMPLETED;

            $receptionChecklist->update([
                'status' => $checklistStatus,
                'completed_by' => $user->id,
                'completed_at' => $receivedAt,
                'notes' => $data['notes'] ?? null,
            ]);

            $transfer->guide?->update([
                'status' => $guideStatus,
                'received_at' => $receivedAt,
                'received_by' => $user->id,
            ]);

            $transfer->update([
                'status' => $transferStatus,
                'received_at' => $receivedAt,
                'received_by' => $user->id,
                'processed_at' => $receivedAt,
                'notes' => $data['notes'] ?? $transfer->notes,
            ]);

            $this->audit->record(
                action: 'inventory_transfer.received',
                entity: $transfer,
                user: $user,
                newValues: [
                    'status' => $transferStatus,
                    'received_at' => $receivedAt->format('c'),
                    'has_differences' => $hasDifferences,
                ],
            );

            return $transfer->refresh()->load(['fromWarehouse', 'toWarehouse', 'guide.checklists.items', 'items.product']);
        });
    }

    public function cancel(User $user, InventoryTransfer $transfer, array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($user, $transfer, $data): InventoryTransfer {
            $transfer = InventoryTransfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->validation_mode !== InventoryTransfer::VALIDATION_LOGISTICS) {
                throw ValidationException::withMessages([
                    'transfer' => 'Los traslados simples no se cancelan desde este endpoint.',
                ]);
            }

            $cancellable = [
                InventoryTransfer::STATUS_REQUESTED,
                InventoryTransfer::STATUS_PREPARED,
                InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES,
            ];

            if (! in_array($transfer->status, $cancellable, true)) {
                $message = match ($transfer->status) {
                    InventoryTransfer::STATUS_DISPATCHED => 'El traslado ya fue despachado y esta en transito. Espere la recepcion o gestione las diferencias.',
                    InventoryTransfer::STATUS_COMPLETED,
                    InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES => 'El traslado ya fue completado y es historico; no se puede cancelar.',
                    InventoryTransfer::STATUS_CANCELLED => 'El traslado ya esta cancelado.',
                    InventoryTransfer::STATUS_REJECTED => 'El traslado ya fue rechazado.',
                    default => 'El traslado no se puede cancelar en su estado actual.',
                };

                throw ValidationException::withMessages([
                    'status' => $message,
                ]);
            }

            $transfer->loadMissing(['fromWarehouse', 'items.product']);

            $cancelledAt = $data['cancelled_at'] ?? now();
            $reason = $data['cancellation_reason'];

            $releasedItemsCount = 0;
            $releasedUnitsCount = 0;

            foreach ($transfer->items as $item) {
                $preparedQuantity = (float) ($item->prepared_quantity ?? 0);
                $preparedUnitIds = $item->prepared_product_unit_ids ?? [];

                if ($preparedQuantity > 0) {
                    $movement = $this->inventory->release(
                        warehouse: $transfer->fromWarehouse,
                        product: $item->product,
                        quantity: $preparedQuantity,
                        createdBy: $user,
                        reason: "Cancelacion {$transfer->guide_number}: {$reason}",
                        referenceType: InventoryTransfer::class,
                        referenceId: $transfer->id,
                    );

                    $this->syncCatalog->stockMovementCreated($movement);
                    $releasedItemsCount++;
                }

                if ($preparedUnitIds !== []) {
                    ProductUnit::query()
                        ->whereIn('id', $preparedUnitIds)
                        ->lockForUpdate()
                        ->get()
                        ->each(function (ProductUnit $unit) use (&$releasedUnitsCount): void {
                            if ($unit->status === ProductUnit::STATUS_RESERVED) {
                                $unit->update([
                                    'status' => ProductUnit::STATUS_AVAILABLE,
                                    'released_stock_movement_id' => null,
                                ]);
                                $this->syncCatalog->productUnitUpdated($unit->refresh());
                                $releasedUnitsCount++;
                            }
                        });
                }
            }

            $previousStatus = $transfer->status;
            $transfer->update([
                'status' => InventoryTransfer::STATUS_CANCELLED,
                'cancelled_at' => $cancelledAt,
                'cancelled_by' => $user->id,
            ]);

            $this->audit->record(
                action: 'inventory_transfer.cancelled',
                entity: $transfer,
                user: $user,
                oldValues: [
                    'status' => $previousStatus,
                ],
                newValues: [
                    'status' => InventoryTransfer::STATUS_CANCELLED,
                    'cancelled_at' => $cancelledAt instanceof \DateTimeInterface ? $cancelledAt->format('c') : (string) $cancelledAt,
                    'cancellation_reason' => $reason,
                    'released_items_count' => $releasedItemsCount,
                    'released_units_count' => $releasedUnitsCount,
                ],
            );

            return $transfer->refresh()->load([
                'fromWarehouse',
                'toWarehouse',
                'guide.checklists.items',
                'items.product',
                'canceller',
            ]);
        });
    }

    public function resolveDifferences(User $user, InventoryTransfer $transfer, array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($user, $transfer, $data): InventoryTransfer {
            $transfer = InventoryTransfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->validation_mode !== InventoryTransfer::VALIDATION_LOGISTICS) {
                throw ValidationException::withMessages([
                    'transfer' => 'Los traslados simples no tienen diferencias que resolver.',
                ]);
            }

            if ($transfer->status !== InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se pueden resolver diferencias en traslados completados con diferencias.',
                ]);
            }

            $transfer->loadMissing(['fromWarehouse', 'toWarehouse', 'items.product']);

            $itemsById = $transfer->items->keyBy('id');
            $payloadItems = collect($data['items'] ?? []);
            $submittedItemIds = $payloadItems->pluck('inventory_transfer_item_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $candidates = $itemsById->filter(
                fn (InventoryTransferItem $item): bool => (float) ($item->difference_quantity ?? 0) > 0
                    && $item->resolution_status === InventoryTransferItem::RESOLUTION_UNRESOLVED
            );

            if ($candidates->isEmpty()) {
                throw ValidationException::withMessages([
                    'transfer' => 'El traslado no tiene diferencias pendientes por resolver.',
                ]);
            }

            $previousResolutionStatus = $transfer->resolution_status;
            $appliedActions = [];
            $lossMovementsCreated = 0;
            $removedUnitsCount = 0;

            foreach ($payloadItems as $index => $payloadItem) {
                $itemId = (int) $payloadItem['inventory_transfer_item_id'];

                if (! $itemsById->has($itemId)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.inventory_transfer_item_id" => 'El item no pertenece al traslado.',
                    ]);
                }

                /** @var InventoryTransferItem $item */
                $item = $itemsById->get($itemId);

                if (! $candidates->has($itemId)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.inventory_transfer_item_id" => 'El item no tiene diferencias pendientes por resolver.',
                    ]);
                }

                $action = $payloadItem['action'];
                $notes = $payloadItem['notes'] ?? null;
                $resolvedAt = $payloadItem['resolved_at'] ?? now();
                $differenceQuantity = (float) $item->difference_quantity;
                $product = $item->product;

                switch ($action) {
                    case InventoryTransferItem::RESOLUTION_INVESTIGATING:
                        $movementId = null;
                        break;

                    case InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS:
                        $movementId = null;
                        break;

                    case InventoryTransferItem::RESOLUTION_ADJUSTED_MANUALLY:
                        $manualQuantity = (float) ($payloadItem['quantity'] ?? 0);

                        if ($manualQuantity <= 0) {
                            throw ValidationException::withMessages([
                                "items.{$index}.quantity" => 'La cantidad de ajuste manual debe ser mayor que cero.',
                            ]);
                        }

                        $movement = $this->inventory->adjustmentOut(
                            warehouse: $transfer->toWarehouse,
                            product: $product,
                            quantity: $manualQuantity,
                            createdBy: $user,
                            reason: "Ajuste manual por diferencia en recepcion {$transfer->guide_number}",
                            referenceType: InventoryTransfer::class,
                            referenceId: $transfer->id,
                        );
                        $this->syncCatalog->stockMovementCreated($movement);
                        $movementId = $movement->id;
                        $lossMovementsCreated++;
                        break;

                    case InventoryTransferItem::RESOLUTION_RETURNED_TO_ORIGIN:
                        throw ValidationException::withMessages([
                            "items.{$index}.action" => 'La devolucion al origen no esta habilitada en esta fase.',
                        ]);

                    default:
                        throw ValidationException::withMessages([
                            "items.{$index}.action" => 'Accion de resolucion no soportada.',
                        ]);
                }

                $removedUnitIds = [];

                if (in_array($action, [
                    InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS,
                    InventoryTransferItem::RESOLUTION_ADJUSTED_MANUALLY,
                ], true) && $product->requiresSerializedTracking()) {
                    $expectedUnitIds = $item->prepared_product_unit_ids ?? [];
                    $receivedUnitIds = $item->received_product_unit_ids ?? [];
                    $missingUnitIds = array_values(array_diff($expectedUnitIds, $receivedUnitIds));

                    if ($missingUnitIds !== []) {
                        ProductUnit::query()
                            ->whereIn('id', $missingUnitIds)
                            ->lockForUpdate()
                            ->get()
                            ->each(function (ProductUnit $unit) use ($item, &$removedUnitIds, &$removedUnitsCount): void {
                                if (in_array($unit->status, [
                                    ProductUnit::STATUS_AVAILABLE,
                                    ProductUnit::STATUS_RESERVED,
                                ], true)) {
                                    $unit->update([
                                        'status' => ProductUnit::STATUS_REMOVED,
                                        'released_stock_movement_id' => $item->out_stock_movement_id,
                                    ]);
                                    $this->syncCatalog->productUnitUpdated($unit->refresh());
                                    $removedUnitIds[] = $unit->id;
                                    $removedUnitsCount++;
                                }
                            });
                    }
                }

                $item->update([
                    'resolution_status' => $action,
                    'resolution_notes' => $notes,
                    'resolved_at' => $resolvedAt,
                    'resolved_by' => $user->id,
                ]);

                $appliedActions[] = [
                    'item_id' => $item->id,
                    'action' => $action,
                    'movement_id' => $movementId,
                    'removed_unit_ids' => $removedUnitIds,
                ];
            }

            $remainingUnresolved = $transfer->items()
                ->get()
                ->filter(fn (InventoryTransferItem $item): bool => (float) ($item->difference_quantity ?? 0) > 0
                    && $item->resolution_status === InventoryTransferItem::RESOLUTION_UNRESOLVED)
                ->count();

            $hasInvestigating = $transfer->items()
                ->get()
                ->contains(fn (InventoryTransferItem $item): bool => $item->resolution_status === InventoryTransferItem::RESOLUTION_INVESTIGATING);

            if ($remainingUnresolved > 0) {
                $newResolutionStatus = InventoryTransfer::RESOLUTION_UNRESOLVED;
            } elseif ($hasInvestigating) {
                $newResolutionStatus = InventoryTransfer::RESOLUTION_PARTIAL;
            } else {
                $newResolutionStatus = InventoryTransfer::RESOLUTION_RESOLVED;
            }

            $transfer->resolution_status = $newResolutionStatus;
            $transfer->resolution_notes = $data['notes'] ?? null;
            $transfer->resolved_at = $newResolutionStatus === InventoryTransfer::RESOLUTION_RESOLVED ? now() : null;
            $transfer->resolved_by = $newResolutionStatus === InventoryTransfer::RESOLUTION_RESOLVED ? $user->id : null;

            $newTransferStatus = $newResolutionStatus === InventoryTransfer::RESOLUTION_RESOLVED
                ? InventoryTransfer::STATUS_COMPLETED
                : $transfer->status;

            $transfer->status = $newTransferStatus;
            $transfer->save();

            $this->audit->record(
                action: 'inventory_transfer.differences_resolved',
                entity: $transfer,
                user: $user,
                oldValues: [
                    'resolution_status' => $previousResolutionStatus,
                    'status' => $transfer->wasChanged('status') ? $transfer->getOriginal('status') : $transfer->status,
                ],
                newValues: [
                    'resolution_status' => $newResolutionStatus,
                    'status' => $newTransferStatus,
                    'resolution_notes' => $transfer->resolution_notes,
                    'loss_movements_created' => $lossMovementsCreated,
                    'removed_units_count' => $removedUnitsCount,
                    'applied_actions' => $appliedActions,
                ],
            );

            return $transfer->refresh()->load([
                'fromWarehouse',
                'toWarehouse',
                'guide.checklists.items',
                'items.product',
                'items.resolver',
                'resolver',
            ]);
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

        $this->audit->record(
            action: 'inventory_transfer.created',
            entity: $transfer,
            user: $user,
            newValues: [
                'validation_mode' => $transfer->validation_mode,
                'status' => $transfer->status,
                'from_warehouse_id' => $transfer->from_warehouse_id,
                'to_warehouse_id' => $transfer->to_warehouse_id,
                'items_count' => count($data['items']),
            ],
        );

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

                $this->syncCatalog->productUnitUpdated($unit->refresh());
            });
    }

    private function markDispatchedProductUnits(array $unitIds, int $movementId): void
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
                    'released_stock_movement_id' => $movementId,
                ]);

                $this->syncCatalog->productUnitUpdated($unit->refresh());
            });
    }

    private function ensureReceptionChecklist(InventoryTransfer $transfer): InventoryTransferChecklist
    {
        $transfer->loadMissing(['guide.checklists.items', 'items']);

        $receptionChecklist = $transfer->guide?->checklists
            ->firstWhere('stage', InventoryTransferChecklist::STAGE_RECEPTION);

        if ($receptionChecklist) {
            return $receptionChecklist;
        }

        if (! $transfer->guide) {
            throw ValidationException::withMessages([
                'transfer' => 'El traslado no tiene guia para generar el checklist de recepcion.',
            ]);
        }

        $receptionChecklist = InventoryTransferChecklist::create([
            'inventory_transfer_id' => $transfer->id,
            'inventory_transfer_guide_id' => $transfer->guide->id,
            'stage' => InventoryTransferChecklist::STAGE_RECEPTION,
            'status' => InventoryTransferChecklist::STATUS_PENDING,
        ]);

        $transfer->items()->get()->each(function (InventoryTransferItem $item) use ($receptionChecklist): void {
            InventoryTransferChecklistItem::create([
                'inventory_transfer_checklist_id' => $receptionChecklist->id,
                'inventory_transfer_item_id' => $item->id,
                'product_id' => $item->product_id,
                'expected_quantity' => $item->prepared_quantity ?? 0,
                'checked_quantity' => 0,
                'difference_quantity' => 0,
                'expected_product_unit_ids' => $item->prepared_product_unit_ids,
            ]);
        });

        return $receptionChecklist->load('items');
    }

    private function validateReceivedProductUnits(
        InventoryTransfer $transfer,
        InventoryTransferItem $item,
        array $unitIds,
        int $itemIndex,
    ): void {
        if (count($unitIds) !== count(array_unique($unitIds))) {
            throw ValidationException::withMessages([
                "items.{$itemIndex}.received_product_unit_ids" => 'No se puede repetir el mismo IMEI o serial en la recepcion.',
            ]);
        }

        $expectedUnitIds = $item->prepared_product_unit_ids ?? [];

        if ($expectedUnitIds !== [] && array_diff($unitIds, $expectedUnitIds) !== []) {
            throw ValidationException::withMessages([
                "items.{$itemIndex}.received_product_unit_ids" => 'Solo se pueden recibir IMEIs o seriales despachados en la guia.',
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
                "items.{$itemIndex}.received_product_unit_ids" => 'Uno o mas IMEIs o seriales no existen.',
            ]);
        }

        foreach ($unitIds as $unitIndex => $unitId) {
            $unit = $units->get($unitId);

            if ((int) $unit->product_id !== (int) $item->product_id
                || (int) $unit->warehouse_id !== (int) $transfer->from_warehouse_id
                || $unit->status !== ProductUnit::STATUS_RESERVED) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.received_product_unit_ids.{$unitIndex}" => 'El IMEI o serial no esta despachado y pendiente por recibir.',
                ]);
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
                    'status' => ProductUnit::STATUS_AVAILABLE,
                    'acquired_stock_movement_id' => $movementId,
                    'released_stock_movement_id' => null,
                ]);

                $this->syncCatalog->productUnitUpdated($unit->refresh());
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
