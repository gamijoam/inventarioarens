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
        $totals = DB::table('stock_movements')
            ->where('tenant_id', $product->tenant_id)
            ->where('product_id', $product->product_id ?? $product->id)
            ->whereNotNull('unit_cost')
            ->whereIn('type', self::COST_TYPES)
            ->selectRaw('SUM(CASE WHEN type IN (?, ?, ?, ?) THEN quantity ELSE 0 END) as qty_in', [
                'purchase', 'adjustment_in', 'return_in', 'transfer_in',
            ])
            ->selectRaw('SUM(CASE WHEN type IN (?, ?, ?, ?) THEN quantity ELSE 0 END) as qty_out', [
                'purchase_return', 'adjustment_out', 'return_out', 'transfer_out',
            ])
            ->selectRaw('SUM(CASE WHEN type IN (?, ?, ?, ?) THEN quantity * unit_cost ELSE 0 END) - SUM(CASE WHEN type IN (?, ?, ?) THEN quantity * unit_cost ELSE 0 END) as net_value', [
                'purchase', 'adjustment_in', 'return_in', 'transfer_in',
                'purchase_return', 'return_out', 'transfer_out',
            ])
            ->first();

        if (! $totals || (float) $totals->qty_in <= 0) {
            $product->average_cost = null;
            $product->save();

            return null;
        }

        $wac = (float) $totals->net_value / (float) $totals->qty_in;

        $product->average_cost = round($wac, 4);
        $product->save();

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
