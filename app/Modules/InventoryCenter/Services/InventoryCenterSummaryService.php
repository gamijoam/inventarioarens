<?php

namespace App\Modules\InventoryCenter\Services;

use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class InventoryCenterSummaryService
{
    public function __construct(private readonly TenantManager $tenantManager)
    {
    }

    public function summary(array $filters): array
    {
        $threshold = (float) ($filters['low_stock_threshold'] ?? 3);
        $limit = min(max((int) ($filters['limit'] ?? 24), 1), 50);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $products = $this->products($filters, $threshold, $limit, $page);

        return [
            'filters' => [
                'search' => $filters['search'] ?? null,
                'tracking_type' => $filters['tracking_type'] ?? null,
                'stock_status' => $filters['stock_status'] ?? 'all',
                'low_stock_threshold' => $threshold,
                'limit' => $limit,
                'page' => $page,
            ],
            'metrics' => $this->metrics($threshold),
            'products' => $products['data'],
            'pagination' => $products['pagination'],
        ];
    }

    private function metrics(float $threshold): array
    {
        $stock = $this->stockTotals();

        $productStockQuery = $this->productStockQuery($stock)
            ->where('products.is_active', true);

        return [
            'total_products' => Product::query()->where('is_active', true)->count(),
            'serialized_products' => Product::query()
                ->where('is_active', true)
                ->where('tracking_type', Product::TRACKING_SERIALIZED)
                ->count(),
            'quantity_products' => Product::query()
                ->where('is_active', true)
                ->where('tracking_type', Product::TRACKING_QUANTITY)
                ->count(),
            'available_quantity' => $this->roundStock((float) DB::table('stock_balances')
                ->where('tenant_id', $this->tenantManager->require()->id)
                ->sum('quantity_available')),
            'reserved_quantity' => $this->roundStock((float) DB::table('stock_balances')
                ->where('tenant_id', $this->tenantManager->require()->id)
                ->sum('quantity_reserved')),
            'damaged_quantity' => $this->roundStock((float) DB::table('stock_balances')
                ->where('tenant_id', $this->tenantManager->require()->id)
                ->sum('quantity_damaged')),
            'low_stock_count' => (clone $productStockQuery)
                ->whereRaw('COALESCE(stock_totals.quantity_available, 0) <= ?', [$threshold])
                ->count('products.id'),
            'without_stock_count' => (clone $productStockQuery)
                ->whereRaw('COALESCE(stock_totals.quantity_available, 0) <= 0')
                ->count('products.id'),
        ];
    }

    private function products(array $filters, float $threshold, int $limit, int $page): array
    {
        $query = $this->productStockQuery($this->stockTotals())
            ->select([
                'products.id',
                'products.name',
                'products.sku',
                'products.tracking_type',
                'products.base_price',
                'products.sale_currency',
                'products.is_active',
            ])
            ->selectRaw('COALESCE(stock_totals.quantity_available, 0) as quantity_available')
            ->selectRaw('COALESCE(stock_totals.quantity_reserved, 0) as quantity_reserved')
            ->selectRaw('COALESCE(stock_totals.quantity_damaged, 0) as quantity_damaged')
            ->where('products.is_active', true);

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($query) use ($search): void {
                $query
                    ->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.sku', 'like', "%{$search}%");
            });
        }

        if ($trackingType = $filters['tracking_type'] ?? null) {
            $query->where('products.tracking_type', $trackingType);
        }

        match ($filters['stock_status'] ?? 'all') {
            'available' => $query->whereRaw('COALESCE(stock_totals.quantity_available, 0) > ?', [$threshold]),
            'low' => $query->whereRaw('COALESCE(stock_totals.quantity_available, 0) > 0')
                ->whereRaw('COALESCE(stock_totals.quantity_available, 0) <= ?', [$threshold]),
            'out' => $query->whereRaw('COALESCE(stock_totals.quantity_available, 0) <= 0'),
            default => null,
        };

        $total = (clone $query)->count('products.id');
        $lastPage = max((int) ceil($total / $limit), 1);
        $page = min($page, $lastPage);

        $products = $query
            ->orderBy('products.name')
            ->forPage($page, $limit)
            ->limit($limit)
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'tracking_type' => $product->tracking_type,
                'base_price' => $product->base_price === null ? null : (float) $product->base_price,
                'sale_currency' => $product->sale_currency,
                'stock' => [
                    'available' => $this->roundStock((float) $product->quantity_available),
                    'reserved' => $this->roundStock((float) $product->quantity_reserved),
                    'damaged' => $this->roundStock((float) $product->quantity_damaged),
                    'status' => $this->stockStatus((float) $product->quantity_available, $threshold),
                ],
            ])
            ->all();

        return [
            'data' => $products,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : (($page - 1) * $limit) + 1,
                'to' => min($page * $limit, $total),
                'has_previous' => $page > 1,
                'has_next' => $page < $lastPage,
            ],
        ];
    }

    private function productStockQuery(QueryBuilder $stockTotals): \Illuminate\Database\Eloquent\Builder
    {
        return Product::query()
            ->leftJoinSub($stockTotals, 'stock_totals', 'stock_totals.product_id', '=', 'products.id');
    }

    private function stockTotals(): QueryBuilder
    {
        return DB::table('stock_balances')
            ->select('product_id')
            ->selectRaw('SUM(quantity_available) as quantity_available')
            ->selectRaw('SUM(quantity_reserved) as quantity_reserved')
            ->selectRaw('SUM(quantity_damaged) as quantity_damaged')
            ->where('tenant_id', $this->tenantManager->require()->id)
            ->groupBy('product_id');
    }

    private function stockStatus(float $available, float $threshold): string
    {
        if ($available <= 0) {
            return 'out';
        }

        return $available <= $threshold ? 'low' : 'available';
    }

    private function roundStock(float $value): float
    {
        return round($value, 4);
    }
}
