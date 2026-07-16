<?php

namespace App\Modules\InventoryCenter\Services;

use App\Modules\Products\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recalcula el precio de venta (base_price) de un producto a partir
 * de su costo promedio ponderado (WAC) y su margen de ganancia.
 *
 * Formula:   new_base_price = average_cost * (1 + profit_margin / 100)
 *
 * Redondeo: round(new_base_price, 2). El cliente puede redondear despues
 * a un valor psicologico (.99, .95) manualmente desde la UI.
 *
 * Si el producto no tiene profit_margin definido, NO se recalcula
 * (devuelve excepcion 422 con mensaje claro).
 *
 * Si no hay stock (WAC = null), tampoco se puede calcular.
 */
class RecalculatePriceService
{
    /**
     * Recalcula el base_price de un producto segun su WAC y margen.
     *
     * Si $overrideMargin viene con un valor, se actualiza el profit_margin
     * del producto antes de calcular (caso "cambiar margen y recalcular").
     *
     * @return array{base_price: float, profit_margin: float, wac: ?float}
     */
    public function recalculate(Product $product, ?float $overrideMargin = null): array
    {
        $margin = $overrideMargin ?? $product->effectiveProfitMargin();

        if ($margin === null) {
            throw ValidationException::withMessages([
                'profit_margin' => 'No hay margen definido. Define uno antes de recalcular.',
            ]);
        }

        $wac = $product->average_cost === null ? null : (float) $product->average_cost;

        if ($wac === null) {
            throw ValidationException::withMessages([
                'average_cost' => 'No hay costo promedio (WAC) calculado. Registra una compra primero.',
            ]);
        }

        if ($overrideMargin !== null) {
            $product->profit_margin = round($overrideMargin, 2);
            $product->save();
        }

        $newPrice = round($wac * (1 + ($margin / 100)), 2);
        $product->base_price = $newPrice;
        $product->save();

        return [
            'base_price' => $newPrice,
            'profit_margin' => (float) $margin,
            'wac' => $wac,
        ];
    }
}
