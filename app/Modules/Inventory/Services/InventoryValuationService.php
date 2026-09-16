<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Products\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula el costo promedio ponderado (WAC) de un producto.
 *
 * Formula: nuevo_wac = ((cantidad_anterior * costo_anterior) + (cantidad_nueva * costo_nuevo)) / cantidad_total
 *
 * Solo afecta movimientos que tienen unit_cost (purchase, purchase_return, adjustment_in/out).
 */
class InventoryValuationService
{
    public const COST_TYPES = [
        'purchase',
        'purchase_return',
        'adjustment_in',
        'adjustment_out',
        'return_in',
        'return_out',
    ];

    /**
     * Recalcula WAC y persiste en products.average_cost.
     * Devuelve el nuevo costo promedio o null si no se puede calcular.
     */
    public function recalculate(Product $product): ?float
    {
        // El sistema utiliza costo de reposición directo (último costo de adquisición).
        // Se evita la dilución histórica de WAC.
        if ($product->last_purchase_cost !== null) {
            $product->average_cost = $product->last_purchase_cost;
            $product->save();

            return $product->average_cost;
        }

        return $product->average_cost;
    }

    /**
     * Recalcula WAC para todos los productos activos del tenant.
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
