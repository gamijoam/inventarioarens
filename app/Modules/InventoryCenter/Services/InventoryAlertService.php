<?php

namespace App\Modules\InventoryCenter\Services;

use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

class InventoryAlertService
{
    public const STATUS_OUT = 'out';

    public const STATUS_CRITICAL = 'critical';

    public const STATUS_LOW = 'low';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_OVERSTOCK = 'overstock';

    public function __construct(private readonly TenantManager $tenantManager) {}

    /**
     * Devuelve el estado detallado del stock de un producto en todos los almacenes.
     */
    public function stockStatus(Product $product): array
    {
        $totals = $this->stockTotals($product->id);

        $available = (float) $totals->quantity_available;
        $reserved = (float) $totals->quantity_reserved;
        $damaged = (float) $totals->quantity_damaged;

        $status = $this->resolveStatus($product, $available);

        $suggested = $this->suggestedPurchase($product, $available);

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'available' => $available,
            'reserved' => $reserved,
            'damaged' => $damaged,
            'physical' => round($available + $reserved + $damaged, 4),
            'min_stock' => $product->min_stock === null ? null : (float) $product->min_stock,
            'max_stock' => $product->max_stock === null ? null : (float) $product->max_stock,
            'reorder_quantity' => $product->reorder_quantity === null ? null : (float) $product->reorder_quantity,
            'suggested_purchase' => $suggested,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'has_min_stock' => $product->hasMinStock(),
            'has_max_stock' => $product->hasMaxStock(),
        ];
    }

    /**
     * Productos que requieren reposicion: available <= min_stock (o <= 0 si no tiene min).
     */
    public function reorderSuggestions(array $filters = []): array
    {
        $tenantId = $this->tenantManager->require()->id;
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;

        $stockTotals = $this->allStockTotalsSubQuery($tenantId, $warehouseId);

        $query = Product::query()
            ->leftJoinSub($stockTotals, 'st', 'st.product_id', '=', 'products.id')
            ->where('products.is_active', true)
            ->where('products.track_stock', true)
            ->whereNotNull('products.min_stock')
            ->whereRaw('COALESCE(st.quantity_available, 0) <= products.min_stock')
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                'products.min_stock',
                'products.max_stock',
                'products.reorder_quantity',
                'products.brand_id'
            )
            ->selectRaw('COALESCE(st.quantity_available, 0) as available')
            ->selectRaw('COALESCE(st.quantity_reserved, 0) as reserved')
            ->orderByRaw('COALESCE(st.quantity_available, 0) / NULLIF(products.min_stock, 0) ASC')
            ->limit($limit);

        $rows = $query->get()->map(function ($row) {
            $min = (float) $row->min_stock;
            $available = (float) $row->available;
            $max = $row->max_stock !== null ? (float) $row->max_stock : null;

            $suggested = null;
            if ($max !== null) {
                $suggested = max(0, $max - $available);
            } elseif ($row->reorder_quantity !== null) {
                $suggested = (float) $row->reorder_quantity;
            } else {
                $suggested = max(0, $min - $available);
            }

            $status = $available <= 0
                ? self::STATUS_OUT
                : ($available <= $min / 2 ? self::STATUS_CRITICAL : self::STATUS_LOW);

            return [
                'product_id' => (int) $row->id,
                'product_name' => $row->name,
                'sku' => $row->sku,
                'available' => round($available, 4),
                'reserved' => round((float) $row->reserved, 4),
                'min_stock' => $min,
                'max_stock' => $max,
                'reorder_quantity' => $row->reorder_quantity !== null ? (float) $row->reorder_quantity : null,
                'suggested_purchase' => round($suggested, 4),
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'gap_to_min' => round($min - $available, 4),
            ];
        });

        return [
            'data' => $rows->values()->all(),
            'summary' => [
                'total_suggestions' => $rows->count(),
                'critical_count' => $rows->where('status', self::STATUS_CRITICAL)->count(),
                'low_count' => $rows->where('status', self::STATUS_LOW)->count(),
                'out_count' => $rows->where('status', self::STATUS_OUT)->count(),
            ],
        ];
    }

    /**
     * Resumen global de alertas: out, low, overstock.
     */
    public function summary(float $fallbackThreshold = 3): array
    {
        $tenantId = $this->tenantManager->require()->id;
        $stockTotals = $this->allStockTotalsSubQuery($tenantId, null);

        $base = Product::query()
            ->leftJoinSub($stockTotals, 'st', 'st.product_id', '=', 'products.id')
            ->where('products.is_active', true)
            ->where('products.track_stock', true)
            ->selectRaw('COUNT(*) FILTER (WHERE COALESCE(st.quantity_available, 0) <= 0) as out_count')
            ->selectRaw('COUNT(*) FILTER (WHERE COALESCE(st.quantity_available, 0) > 0 AND COALESCE(st.quantity_available, 0) <= COALESCE(products.min_stock, ?)) as low_count', [$fallbackThreshold])
            ->selectRaw('COUNT(*) FILTER (WHERE products.min_stock IS NOT NULL) as with_min_stock');

        $row = $base->first();

        return [
            'out_count' => (int) ($row->out_count ?? 0),
            'low_count' => (int) ($row->low_count ?? 0),
            'with_min_stock_count' => (int) ($row->with_min_stock ?? 0),
            'fallback_threshold' => $fallbackThreshold,
        ];
    }

    private function stockTotals(int $productId): object
    {
        $tenantId = $this->tenantManager->require()->id;

        return DB::table('stock_balances')
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->selectRaw('COALESCE(SUM(quantity_available), 0) as quantity_available')
            ->selectRaw('COALESCE(SUM(quantity_reserved), 0) as quantity_reserved')
            ->selectRaw('COALESCE(SUM(quantity_damaged), 0) as quantity_damaged')
            ->first() ?? (object) ['quantity_available' => 0, 'quantity_reserved' => 0, 'quantity_damaged' => 0];
    }

    private function allStockTotalsSubQuery(int $tenantId, ?int $warehouseId)
    {
        $q = DB::table('stock_balances')
            ->select('product_id')
            ->selectRaw('SUM(quantity_available) as quantity_available')
            ->selectRaw('SUM(quantity_reserved) as quantity_reserved')
            ->where('tenant_id', $tenantId)
            ->groupBy('product_id');

        if ($warehouseId !== null) {
            $q->where('warehouse_id', $warehouseId);
        }

        return $q;
    }

    private function resolveStatus(Product $product, float $available): string
    {
        $hasMin = $product->hasMinStock();
        $hasMax = $product->hasMaxStock();

        if ($available <= 0) {
            return self::STATUS_OUT;
        }

        if ($hasMin && $available <= ((float) $product->min_stock) / 2) {
            return self::STATUS_CRITICAL;
        }

        if ($hasMin && $available <= (float) $product->min_stock) {
            return self::STATUS_LOW;
        }

        if ($hasMax && $available > (float) $product->max_stock) {
            return self::STATUS_OVERSTOCK;
        }

        return self::STATUS_AVAILABLE;
    }

    private function suggestedPurchase(Product $product, float $available): ?float
    {
        $hasMin = $product->hasMinStock();
        $hasMax = $product->hasMaxStock();

        if (! $hasMin && ! $hasMax) {
            return null;
        }

        if ($hasMax) {
            return max(0, (float) $product->max_stock - $available);
        }

        return max(0, (float) $product->min_stock - $available);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_OUT => 'Sin stock',
            self::STATUS_CRITICAL => 'Critico',
            self::STATUS_LOW => 'Stock bajo',
            self::STATUS_AVAILABLE => 'Disponible',
            self::STATUS_OVERSTOCK => 'Sobre-stock',
            default => $status,
        };
    }
}
