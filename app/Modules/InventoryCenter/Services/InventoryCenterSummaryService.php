<?php

namespace App\Modules\InventoryCenter\Services;

use App\Modules\Products\Models\PriceList;
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
            'alerts' => $this->alerts($threshold),
            'products' => $products['data'],
            'pagination' => $products['pagination'],
        ];
    }

    public function exportCsv(array $filters): string
    {
        $threshold = (float) ($filters['low_stock_threshold'] ?? 3);
        $handle = fopen('php://temp', 'r+');

        fputs($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'Producto',
            'SKU',
            'Tipo de control',
            'Moneda',
            'Precio base',
            'Disponible',
            'Reservado',
            'Dañado',
            'Estado de stock',
        ], ';');

        foreach ($this->productRows($filters, $threshold) as $product) {
            fputcsv($handle, [
                $product['name'],
                $product['sku'],
                $product['tracking_type'] === Product::TRACKING_SERIALIZED ? 'Serializado / IMEI' : 'Por cantidad',
                $product['sale_currency'],
                $product['base_price'] ?? '',
                $product['stock']['available'],
                $product['stock']['reserved'],
                $product['stock']['damaged'],
                match ($product['stock']['status']) {
                    'available' => 'Disponible',
                    'low' => 'Stock bajo',
                    'out' => 'Sin stock',
                    default => $product['stock']['status'],
                },
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
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
        $query = $this->filteredProductsQuery($filters, $threshold);

        $total = (clone $query)->count('products.id');
        $lastPage = max((int) ceil($total / $limit), 1);
        $page = min($page, $lastPage);

        $products = $query
            ->orderBy('products.name')
            ->forPage($page, $limit)
            ->limit($limit)
            ->get()
            ->map(fn (Product $product): array => $this->productRow($product, $threshold))
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

    private function productRows(array $filters, float $threshold): array
    {
        return $this->filteredProductsQuery($filters, $threshold)
            ->orderBy('products.name')
            ->get()
            ->map(fn (Product $product): array => $this->productRow($product, $threshold))
            ->all();
    }

    private function filteredProductsQuery(array $filters, float $threshold): \Illuminate\Database\Eloquent\Builder
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
            $normalizedSearch = mb_strtolower(trim((string) $search));
            $query->where(function ($query) use ($normalizedSearch): void {
                $like = "%{$normalizedSearch}%";
                $query
                    ->whereRaw('LOWER(products.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(products.sku) LIKE ?', [$like]);
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

        return $query;
    }

    private function productRow(Product $product, float $threshold): array
    {
        return [
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
        ];
    }

    private function alerts(float $threshold): array
    {
        $stock = $this->stockTotals();
        $productStockQuery = $this->productStockQuery($stock)
            ->where('products.is_active', true);

        $alerts = [
            $this->alertItem(
                'low_stock',
                'warning',
                'Stock bajo',
                (clone $productStockQuery)
                    ->whereRaw('COALESCE(stock_totals.quantity_available, 0) > 0')
                    ->whereRaw('COALESCE(stock_totals.quantity_available, 0) <= ?', [$threshold])
                    ->count('products.id'),
                'Productos por debajo del mínimo operativo.',
                'Revisar reposición o traslado.',
                $this->productNamesForStock('low', $threshold)
            ),
            $this->alertItem(
                'without_stock',
                'danger',
                'Sin stock',
                (clone $productStockQuery)
                    ->whereRaw('COALESCE(stock_totals.quantity_available, 0) <= 0')
                    ->count('products.id'),
                'Productos activos sin disponibilidad.',
                'Reponer o desactivar si ya no se venden.',
                $this->productNamesForStock('out', $threshold)
            ),
            $this->alertItem(
                'without_base_price',
                'danger',
                'Sin precio base',
                Product::query()
                    ->where('is_active', true)
                    ->whereNull('base_price')
                    ->count(),
                'Productos sin precio base configurado.',
                'Asignar precio antes de vender en POS.',
                $this->productNamesForBasePrice()
            ),
            $this->alertItem(
                'without_warranty_policy',
                'warning',
                'Sin garantía',
                Product::query()
                    ->where('is_active', true)
                    ->whereNull('warranty_policy_id')
                    ->count(),
                'Productos sin política de garantía asignada.',
                'Asignar garantía cuando aplique.',
                $this->productNamesForWarranty()
            ),
            $this->priceListCompletenessAlert(),
        ];

        return array_values(array_filter($alerts, fn (array $alert): bool => $alert['count'] > 0));
    }

    private function priceListCompletenessAlert(): array
    {
        $tenantId = $this->tenantManager->require()->id;
        $activePriceListIds = PriceList::query()
            ->where('is_active', true)
            ->pluck('id');

        if ($activePriceListIds->isEmpty()) {
            return $this->alertItem(
                'missing_price_lists',
                'info',
                'Sin listas activas',
                0,
                'No hay listas de precio activas para validar.',
                'Crear listas como detal, mayor o técnico.',
                []
            );
        }

        $activePriceListCount = $activePriceListIds->count();
        $query = Product::query()
            ->leftJoin('product_prices', function ($join) use ($activePriceListIds, $tenantId): void {
                $join
                    ->on('product_prices.product_id', '=', 'products.id')
                    ->where('product_prices.tenant_id', $tenantId)
                    ->where('product_prices.is_active', true)
                    ->whereIn('product_prices.price_list_id', $activePriceListIds);
            })
            ->where('products.is_active', true)
            ->groupBy('products.id')
            ->havingRaw('COUNT(DISTINCT product_prices.price_list_id) < ?', [$activePriceListCount]);

        $count = (clone $query)->get('products.id')->count();
        $names = (clone $query)
            ->orderBy('products.name')
            ->limit(3)
            ->pluck('products.name')
            ->all();

        return $this->alertItem(
            'missing_price_lists',
            'warning',
            'Listas de precio incompletas',
            $count,
            'Productos sin precio en una o más listas activas.',
            'Completar precios por lista antes de vender.',
            $names
        );
    }

    private function alertItem(
        string $type,
        string $severity,
        string $title,
        int $count,
        string $message,
        string $action,
        array $productNames
    ): array {
        return [
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'count' => $count,
            'message' => $message,
            'action' => $action,
            'product_names' => $productNames,
        ];
    }

    private function productNamesForStock(string $status, float $threshold): array
    {
        $query = $this->productStockQuery($this->stockTotals())
            ->where('products.is_active', true);

        if ($status === 'low') {
            $query
                ->whereRaw('COALESCE(stock_totals.quantity_available, 0) > 0')
                ->whereRaw('COALESCE(stock_totals.quantity_available, 0) <= ?', [$threshold]);
        } else {
            $query->whereRaw('COALESCE(stock_totals.quantity_available, 0) <= 0');
        }

        return $query
            ->orderBy('products.name')
            ->limit(3)
            ->pluck('products.name')
            ->all();
    }

    private function productNamesForBasePrice(): array
    {
        return Product::query()
            ->where('is_active', true)
            ->whereNull('base_price')
            ->orderBy('name')
            ->limit(3)
            ->pluck('name')
            ->all();
    }

    private function productNamesForWarranty(): array
    {
        return Product::query()
            ->where('is_active', true)
            ->whereNull('warranty_policy_id')
            ->orderBy('name')
            ->limit(3)
            ->pluck('name')
            ->all();
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
