<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;

/**
 * Reconstruye el promedio ponderado movil de un producto desde su historial
 * de movimientos: cada entrada con costo mezcla su costo con la existencia
 * restante; las salidas reducen la existencia sin cambiar el promedio.
 */
class InventoryValuationService
{
    public const INCREASE_TYPES = [
        'purchase',
        'sale_return',
        'sale_reversal',
        'adjustment_in',
        'transfer_in',
        'transfer_request_in',
        'return_in',
    ];

    public const DECREASE_TYPES = [
        'sale',
        'purchase_return',
        'adjustment_out',
        'transfer_out',
        'transfer_request_out',
        'return_out',
    ];

    /**
     * Recalcula y persiste products.average_cost. Devuelve el promedio o null
     * si ningun movimiento tiene costo.
     */
    public function recalculate(Product $product): ?float
    {
        $quantity = 0.0;
        $average = null;

        StockMovement::query()
            ->where('product_id', $product->id)
            ->whereIn('type', [...self::INCREASE_TYPES, ...self::DECREASE_TYPES])
            ->orderBy('created_at')
            ->orderBy('id')
            ->each(function (StockMovement $movement) use (&$quantity, &$average): void {
                $moved = (float) $movement->quantity;

                if (in_array($movement->type, self::DECREASE_TYPES, true)) {
                    $quantity -= $moved;

                    return;
                }

                if ($movement->unit_cost !== null) {
                    $cost = (float) $movement->unit_cost;
                    $average = $quantity > 0.0 && $average !== null
                        ? (($quantity * $average) + ($moved * $cost)) / ($quantity + $moved)
                        : $cost;
                }

                $quantity += $moved;
            });

        if ($average === null) {
            return null;
        }

        $product->average_cost = round($average, 4);
        $product->save();

        return (float) $product->average_cost;
    }

    /**
     * Recalcula el promedio para todos los productos activos del tenant.
     */
    public function recalculateAllForTenant(int $tenantId): int
    {
        $count = 0;
        Product::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->chunkById(100, function ($products) use (&$count) {
                foreach ($products as $product) {
                    $this->recalculate($product);
                    $count++;
                }
            });

        return $count;
    }
}
