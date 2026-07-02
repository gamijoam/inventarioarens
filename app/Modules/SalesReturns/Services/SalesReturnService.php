<?php

namespace App\Modules\SalesReturns\Services;

use App\Models\User;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\SalesReturns\Models\SalesReturnItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnService
{
    public function __construct(private readonly InventoryMovementService $inventory)
    {
    }

    public function create(User $user, array $data): SalesReturn
    {
        return DB::transaction(function () use ($user, $data): SalesReturn {
            $sale = Sale::query()->with('items.product', 'items.warehouse')->lockForUpdate()->findOrFail($data['sale_id']);

            if ($sale->status !== Sale::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'sale_id' => 'Solo se pueden devolver ventas confirmadas.',
                ]);
            }

            $salesReturn = SalesReturn::create([
                'sale_id' => $sale->id,
                'status' => SalesReturn::STATUS_PROCESSED,
                'reason' => $data['reason'] ?? null,
                'created_by' => $user->id,
                'processed_at' => now(),
            ]);

            foreach ($data['items'] as $itemData) {
                $saleItem = $sale->items->firstWhere('id', (int) $itemData['sale_item_id']);

                if (! $saleItem) {
                    throw ValidationException::withMessages([
                        'items' => 'El item no pertenece a la venta indicada.',
                    ]);
                }

                $quantity = (float) $itemData['quantity'];
                $this->ensureReturnableQuantity($saleItem, $quantity);

                $productUnitIds = $itemData['product_unit_ids'] ?? [];
                $this->validateProductUnits($saleItem->product, $quantity, $productUnitIds);

                $movement = $this->inventory->saleReturn(
                    warehouse: $saleItem->warehouse,
                    product: $saleItem->product,
                    quantity: $quantity,
                    createdBy: $user,
                    reason: $itemData['reason'] ?? $data['reason'] ?? "Devolucion venta #{$sale->id}",
                    referenceType: SalesReturn::class,
                    referenceId: $salesReturn->id,
                );

                SalesReturnItem::create([
                    'sales_return_id' => $salesReturn->id,
                    'sale_item_id' => $saleItem->id,
                    'warehouse_id' => $saleItem->warehouse_id,
                    'product_id' => $saleItem->product_id,
                    'quantity' => $quantity,
                    'product_unit_ids' => $productUnitIds ?: null,
                    'stock_movement_id' => $movement->id,
                    'condition' => $itemData['condition'] ?? SalesReturnItem::CONDITION_SELLABLE,
                    'reason' => $itemData['reason'] ?? null,
                ]);

                $this->restoreProductUnits($productUnitIds, $itemData['condition'] ?? SalesReturnItem::CONDITION_SELLABLE);
            }

            app(AccountsReceivableService::class)->applySalesReturn($salesReturn->refresh());

            return $salesReturn->refresh()->load(['sale.customer', 'items.product', 'items.warehouse', 'items.stockMovement']);
        });
    }

    private function ensureReturnableQuantity(SaleItem $saleItem, float $quantity): void
    {
        $alreadyReturned = (float) SalesReturnItem::query()
            ->where('sale_item_id', $saleItem->id)
            ->sum('quantity');

        $available = (float) $saleItem->quantity - $alreadyReturned;

        if ($quantity > $available) {
            throw ValidationException::withMessages([
                'items' => "La cantidad a devolver supera lo disponible para el item {$saleItem->id}.",
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
        }
    }

    private function restoreProductUnits(array $productUnitIds, string $condition): void
    {
        if ($productUnitIds === []) {
            return;
        }

        $status = $condition === SalesReturnItem::CONDITION_DAMAGED
            ? ProductUnit::STATUS_DAMAGED
            : ProductUnit::STATUS_AVAILABLE;

        ProductUnit::query()
            ->whereIn('id', $productUnitIds)
            ->update(['status' => $status]);
    }
}
