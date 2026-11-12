<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Services\CompanySettings;
use App\Support\Tenancy\TenantManager;

/**
 * Costo de inventario segun el metodo de la empresa
 * (`tenant_settings.company.inventory_costing_method`):
 *
 * - `last_cost` (por defecto): toda la existencia se valora al costo de la
 *   ultima compra.
 * - `weighted_average`: promedio ponderado movil, mantenido en cada recepcion
 *   de compra sobre la existencia restante.
 *
 * El promedio se mantiene siempre, de modo que cambiar de metodo no requiere
 * reconstruir costos.
 */
class InventoryCostService
{
    public const METHOD_LAST_COST = 'last_cost';

    public const METHOD_WEIGHTED_AVERAGE = 'weighted_average';

    public const METHODS = [self::METHOD_LAST_COST, self::METHOD_WEIGHTED_AVERAGE];

    public function method(): string
    {
        $tenant = app(TenantManager::class)->current();
        $method = $tenant ? (CompanySettings::getForTenant($tenant)['inventory_costing_method'] ?? null) : null;

        return in_array($method, self::METHODS, true) ? $method : self::METHOD_LAST_COST;
    }

    /**
     * Costo unitario en USD para valorar una salida de inventario.
     */
    public function unitCost(Product $product): ?float
    {
        $cost = $this->method() === self::METHOD_WEIGHTED_AVERAGE
            ? ($product->average_cost ?? $product->last_purchase_cost)
            : ($product->last_purchase_cost ?? $product->average_cost);

        return $cost === null ? null : round((float) $cost, 4);
    }

    /**
     * Registra el costo de una recepcion ya aplicada al saldo: actualiza el
     * ultimo costo y el promedio ponderado sobre la existencia previa. No
     * guarda el producto.
     */
    public function registerReceipt(Product $product, float $receivedQuantity, float $unitCost): void
    {
        $unitCost = round($unitCost, 4);
        $stockAfter = (float) StockBalance::query()
            ->where('product_id', $product->id)
            ->selectRaw('COALESCE(SUM(quantity_available + quantity_reserved + quantity_damaged), 0) as total')
            ->value('total');
        $stockBefore = round($stockAfter - $receivedQuantity, 4);
        $previousAverage = $product->average_cost ?? $product->last_purchase_cost;

        $product->average_cost = $stockBefore > 0.0 && $previousAverage !== null && ($stockBefore + $receivedQuantity) > 0.0
            ? round((($stockBefore * (float) $previousAverage) + ($receivedQuantity * $unitCost)) / ($stockBefore + $receivedQuantity), 4)
            : $unitCost;
        $product->last_purchase_cost = $unitCost;
    }
}
