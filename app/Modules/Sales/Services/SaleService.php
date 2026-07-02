<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductPriceService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private readonly ProductPriceService $prices,
        private readonly InventoryMovementService $inventory,
    ) {
    }

    public function createDraft(User $user, array $items): Sale
    {
        return DB::transaction(function () use ($user, $items): Sale {
            $sale = Sale::create([
                'status' => Sale::STATUS_DRAFT,
                'created_by' => $user->id,
            ]);

            $totalBase = 0.0;
            $totalLocal = 0.0;

            foreach ($items as $item) {
                $warehouse = Warehouse::query()->findOrFail($item['warehouse_id']);
                $product = Product::query()->findOrFail($item['product_id']);
                $quantity = (float) $item['quantity'];

                if ($quantity <= 0) {
                    throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
                }

                $quote = $this->prices->quote($product);
                $baseUnitPrice = (float) $quote['base_price_usd'];
                $baseTotal = round($baseUnitPrice * $quantity, 4);
                $unitPrice = (float) $quote['sale_price'];
                $totalAmount = round($unitPrice * $quantity, 4);
                $localTotal = $quote['price_ves'] === null ? 0.0 : round((float) $quote['price_ves'] * $quantity, 4);

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'sale_currency' => $quote['sale_currency'],
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalAmount,
                    'base_unit_price' => $baseUnitPrice,
                    'base_total_amount' => $baseTotal,
                    'exchange_rate_type_id' => $quote['exchange_rate_type_id'],
                    'exchange_rate_type_code' => $quote['exchange_rate_type_code'],
                    'exchange_rate' => $quote['exchange_rate'],
                ]);

                $totalBase += $baseTotal;
                $totalLocal += $localTotal;
            }

            $sale->update([
                'total_base_amount' => $totalBase,
                'total_local_amount' => $totalLocal,
            ]);

            return $sale->refresh()->load(['items.product', 'items.warehouse']);
        });
    }

    public function confirm(Sale $sale, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $user): Sale {
            $sale = Sale::query()->with(['items.product', 'items.warehouse'])->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== Sale::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Solo se pueden confirmar ventas en borrador.']);
            }

            foreach ($sale->items as $item) {
                try {
                    $movement = $this->inventory->sale(
                        warehouse: $item->warehouse,
                        product: $item->product,
                        quantity: (float) $item->quantity,
                        createdBy: $user,
                        reason: "Venta #{$sale->id}",
                        referenceType: Sale::class,
                        referenceId: $sale->id,
                    );
                } catch (InsufficientStockException) {
                    throw ValidationException::withMessages([
                        'stock' => "Stock insuficiente para el producto {$item->product_id}.",
                    ]);
                }

                $item->update(['stock_movement_id' => $movement->id]);
            }

            $sale->update([
                'status' => Sale::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ]);

            return $sale->refresh()->load(['items.product', 'items.warehouse', 'items.stockMovement']);
        });
    }

    public function cancelDraft(Sale $sale): Sale
    {
        if ($sale->status !== Sale::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Solo se pueden cancelar ventas en borrador en esta fase.']);
        }

        $sale->update([
            'status' => Sale::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        return $sale->refresh()->load(['items.product', 'items.warehouse']);
    }
}
