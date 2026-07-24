<?php

namespace App\Modules\ProductExits\Services;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidStockQuantityException;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\ProductExits\Models\ProductExit;
use App\Modules\ProductExits\Models\ProductExitItem;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductExitService
{
    public function __construct(
        private readonly InventoryMovementService $inventory,
        private readonly SyncCatalogOutboxService $syncCatalog,
    ) {}

    public function create(User $user, array $data): ProductExit
    {
        return DB::transaction(function () use ($user, $data): ProductExit {
            $this->validateItems($data['items']);

            $sequence = $this->nextSequence();
            $exit = ProductExit::create([
                'sequence' => $sequence,
                'document_number' => 'SAL-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'reason' => $data['reason'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => ProductExit::STATUS_PROCESSED,
                'created_by' => $user->id,
                'processed_at' => $data['processed_at'] ?? now(),
            ]);

            foreach ($data['items'] as $item) {
                $warehouse = Warehouse::query()->findOrFail($item['warehouse_id']);
                $product = Product::query()->findOrFail($item['product_id']);
                $quantity = (float) $item['quantity'];

                try {
                    $movement = $data['reason'] === ProductExit::REASON_DAMAGED
                        ? $this->inventory->markDamaged(
                            warehouse: $warehouse,
                            product: $product,
                            quantity: $quantity,
                            createdBy: $user,
                            reason: "Salida {$exit->document_number}: {$exit->reason}",
                            referenceType: ProductExit::class,
                            referenceId: $exit->id,
                        )
                        : $this->inventory->adjustmentOut(
                            warehouse: $warehouse,
                            product: $product,
                            quantity: $quantity,
                            createdBy: $user,
                            reason: "Salida {$exit->document_number}: {$exit->reason}",
                            referenceType: ProductExit::class,
                            referenceId: $exit->id,
                        );
                } catch (InsufficientStockException|InvalidStockQuantityException $exception) {
                    throw ValidationException::withMessages([
                        'items' => $exception->getMessage(),
                    ]);
                }

                $unitIds = $item['product_unit_ids'] ?? [];

                ProductExitItem::create([
                    'product_exit_id' => $exit->id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'stock_movement_id' => $movement->id,
                    'product_unit_ids' => $unitIds ?: null,
                ]);

                $this->updateProductUnits($unitIds, $movement->id, $data['reason']);
            }

            $exit = $exit->refresh()->load(['items.product', 'items.warehouse']);
            $this->syncCatalog->productExitCreated($exit);

            return $exit;
        });
    }

    private function validateItems(array $items): void
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
                        "items.{$index}.product_unit_ids" => 'Debe indicar una unidad serializada disponible por cada cantidad de salida.',
                    ]);
                }
            } elseif ($unitIds !== []) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_unit_ids" => 'Solo los productos serializados pueden enviar unidades especificas.',
                ]);
            }

            foreach ($unitIds as $unitIndex => $unitId) {
                if (isset($selectedUnitIds[$unitId])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_unit_ids.{$unitIndex}" => 'No se puede repetir la misma unidad en una salida.',
                    ]);
                }

                $selectedUnitIds[$unitId] = true;
                $unit = ProductUnit::query()->lockForUpdate()->find($unitId);

                if (! $unit
                    || (int) $unit->product_id !== (int) $item['product_id']
                    || (int) $unit->warehouse_id !== (int) $item['warehouse_id']
                    || $unit->status !== ProductUnit::STATUS_AVAILABLE) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_unit_ids.{$unitIndex}" => 'La unidad serializada no esta disponible en el producto y almacen indicados.',
                    ]);
                }
            }
        }
    }

    private function updateProductUnits(array $unitIds, int $movementId, string $reason): void
    {
        if ($unitIds === []) {
            return;
        }

        $status = $reason === ProductExit::REASON_DAMAGED
            ? ProductUnit::STATUS_DAMAGED
            : ProductUnit::STATUS_REMOVED;

        ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->lockForUpdate()
            ->get()
            ->each(function (ProductUnit $unit) use ($status, $movementId): void {
                $unit->update([
                    'status' => $status,
                    'released_stock_movement_id' => $movementId,
                ]);
            });
    }

    private function nextSequence(): int
    {
        $lastSequence = ProductExit::query()
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->value('sequence');

        return ((int) $lastSequence) + 1;
    }
}
