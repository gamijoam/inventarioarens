<?php

namespace App\Modules\PurchaseReturns\Services;

use App\Models\User;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\PurchaseReturns\Models\PurchaseReturnItem;
use App\Modules\Purchases\Models\PurchaseItem;
use App\Modules\Purchases\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReturnService
{
    public function __construct(private readonly InventoryMovementService $inventory)
    {
    }

    public function create(User $user, array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($user, $data): PurchaseReturn {
            $purchaseOrder = PurchaseOrder::query()
                ->with(['items.product', 'items.warehouse'])
                ->lockForUpdate()
                ->findOrFail($data['purchase_order_id']);

            if ($purchaseOrder->status !== PurchaseOrder::STATUS_RECEIVED) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => 'Solo se pueden devolver compras recibidas.',
                ]);
            }

            $purchaseReturn = PurchaseReturn::create([
                'purchase_order_id' => $purchaseOrder->id,
                'status' => PurchaseReturn::STATUS_PROCESSED,
                'reason' => $data['reason'] ?? null,
                'created_by' => $user->id,
                'processed_at' => now(),
            ]);

            foreach ($data['items'] as $itemData) {
                $purchaseItem = $purchaseOrder->items->firstWhere('id', (int) $itemData['purchase_item_id']);

                if (! $purchaseItem) {
                    throw ValidationException::withMessages([
                        'items' => 'El item no pertenece a la compra indicada.',
                    ]);
                }

                $quantity = (float) $itemData['quantity'];
                $this->ensureReturnableQuantity($purchaseItem, $quantity);

                $productUnitIds = $itemData['product_unit_ids'] ?? [];
                $this->validateProductUnits($purchaseItem->product, $quantity, $productUnitIds);

                $movement = $this->inventory->purchaseReturn(
                    warehouse: $purchaseItem->warehouse,
                    product: $purchaseItem->product,
                    quantity: $quantity,
                    createdBy: $user,
                    reason: $itemData['reason'] ?? $data['reason'] ?? "Devolucion compra #{$purchaseOrder->id}",
                    referenceType: PurchaseReturn::class,
                    referenceId: $purchaseReturn->id,
                );

                PurchaseReturnItem::create([
                    'purchase_return_id' => $purchaseReturn->id,
                    'purchase_item_id' => $purchaseItem->id,
                    'warehouse_id' => $purchaseItem->warehouse_id,
                    'product_id' => $purchaseItem->product_id,
                    'quantity' => $quantity,
                    'product_unit_ids' => $productUnitIds ?: null,
                    'stock_movement_id' => $movement->id,
                    'reason' => $itemData['reason'] ?? null,
                ]);

                $this->removeProductUnits($productUnitIds, $movement->id);
            }

            return $purchaseReturn->refresh()->load(['purchaseOrder.supplier', 'items.product', 'items.warehouse', 'items.stockMovement']);
        });
    }

    private function ensureReturnableQuantity(PurchaseItem $purchaseItem, float $quantity): void
    {
        $alreadyReturned = (float) PurchaseReturnItem::query()
            ->where('purchase_item_id', $purchaseItem->id)
            ->sum('quantity');

        $available = (float) $purchaseItem->quantity - $alreadyReturned;

        if ($quantity > $available) {
            throw ValidationException::withMessages([
                'items' => "La cantidad a devolver supera lo disponible para el item {$purchaseItem->id}.",
            ]);
        }
    }

    private function validateProductUnits(Product $product, float $quantity, array $productUnitIds): void
    {
        if (! $product->requiresSerializedTracking()) {
            if ($productUnitIds !== []) {
                throw ValidationException::withMessages([
                    'product_unit_ids' => 'Solo los productos serializados pueden devolver unidades especificas.',
                ]);
            }

            return;
        }

        if (count($productUnitIds) !== (int) $quantity || $quantity !== floor($quantity)) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'Los productos serializados requieren una unidad por cada cantidad devuelta.',
            ]);
        }

        if (count($productUnitIds) !== count(array_unique($productUnitIds))) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'No se puede repetir la misma unidad en una devolucion.',
            ]);
        }

        $units = ProductUnit::query()
            ->whereIn('id', $productUnitIds)
            ->get();

        if ($units->count() !== count($productUnitIds)) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'Una o mas unidades no existen en la empresa actual.',
            ]);
        }

        foreach ($units as $unit) {
            if ((int) $unit->product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'product_unit_ids' => 'Una o mas unidades no pertenecen al producto devuelto.',
                ]);
            }

            if ($unit->status !== ProductUnit::STATUS_AVAILABLE) {
                throw ValidationException::withMessages([
                    'product_unit_ids' => 'Solo se pueden devolver unidades disponibles al proveedor.',
                ]);
            }
        }
    }

    private function removeProductUnits(array $productUnitIds, int $movementId): void
    {
        if ($productUnitIds === []) {
            return;
        }

        ProductUnit::query()
            ->whereIn('id', $productUnitIds)
            ->update([
                'status' => ProductUnit::STATUS_REMOVED,
                'released_stock_movement_id' => $movementId,
            ]);
    }
}
