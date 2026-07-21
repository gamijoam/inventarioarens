<?php

namespace App\Modules\InventoryCenter\Services;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Support\Performance\PerformanceProbe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class InventoryCenterProductDetailService
{
    public function detail(Product $product): array
    {
        return PerformanceProbe::measure('InventoryCenter detalle producto total', function () use ($product): array {
            $product->load(['saleExchangeRateType', 'warrantyPolicy']);

            return [
                'product' => $this->product($product),
                'stock' => [
                    'totals' => PerformanceProbe::measure('InventoryCenter detalle stock total', fn (): array => $this->stockTotals($product), 200, ['product_id' => $product->id]),
                    'by_warehouse' => PerformanceProbe::measure('InventoryCenter detalle stock almacenes', fn (): array => $this->stockByWarehouse($product), 250, ['product_id' => $product->id]),
                ],
                'serials' => PerformanceProbe::measure('InventoryCenter detalle seriales recientes', fn (): array => $this->serials($product), 300, ['product_id' => $product->id]),
                'recent_movements' => PerformanceProbe::measure('InventoryCenter detalle movimientos recientes', fn (): array => $this->recentMovements($product), 250, ['product_id' => $product->id]),
                'recent_audits' => PerformanceProbe::measure('InventoryCenter detalle auditorias recientes', fn (): array => $this->recentAudits($product), 250, ['product_id' => $product->id]),
            ];
        }, 900, ['product_id' => $product->id]);
    }

    public function serialsPage(Product $product, array $filters): array
    {
        $startedAt = microtime(true);

        try {
            if (! $product->requiresSerializedTracking()) {
                return [
                    'filters' => $this->pageFilters($filters),
                    'data' => [],
                    'pagination' => $this->pagination(1, $this->limit($filters), 0),
                ];
            }

            $limit = $this->limit($filters);
            $page = $this->page($filters);
            $query = ProductUnit::query()
                ->where('product_id', $product->id)
                ->with('warehouse');

            if ($search = $filters['search'] ?? null) {
                $query->where('serial_number', 'like', "%{$search}%");
            }

            if (($filters['status'] ?? null) && $filters['status'] !== 'all') {
                $query->where('status', $filters['status']);
            }

            if ($warehouseId = $filters['warehouse_id'] ?? null) {
                $query->where('warehouse_id', $warehouseId);
            }

            $total = (clone $query)->count();
            $lastPage = max((int) ceil($total / $limit), 1);
            $page = min($page, $lastPage);

            return [
                'filters' => $this->pageFilters($filters, [
                    'status' => $filters['status'] ?? 'all',
                    'warehouse_id' => isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null,
                ], $limit, $page),
                'data' => $query
                    ->orderBy('status')
                    ->orderBy('serial_number')
                    ->forPage($page, $limit)
                    ->get()
                    ->map(fn (ProductUnit $unit): array => $this->serialUnit($unit))
                    ->all(),
                'pagination' => $this->pagination($page, $limit, $total),
            ];
        } finally {
            PerformanceProbe::log('InventoryCenter seriales pagina', $startedAt, 500, [
                'product_id' => $product->id,
                'search' => $filters['search'] ?? null,
                'status' => $filters['status'] ?? 'all',
            ]);
        }
    }

    public function movementsPage(Product $product, array $filters): array
    {
        $startedAt = microtime(true);

        try {
            $limit = $this->limit($filters);
            $page = $this->page($filters);
            $query = StockMovement::query()
                ->where('product_id', $product->id)
                ->with(['warehouse', 'creator']);

            if ($search = $filters['search'] ?? null) {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('reason', 'like', "%{$search}%")
                        ->orWhere('reference_type', 'like', "%{$search}%");
                });
            }

            if (($filters['type'] ?? null) && $filters['type'] !== 'all') {
                $query->where('type', $filters['type']);
            }

            if ($warehouseId = $filters['warehouse_id'] ?? null) {
                $query->where('warehouse_id', $warehouseId);
            }

            if ($dateFrom = $filters['date_from'] ?? null) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }

            if ($dateTo = $filters['date_to'] ?? null) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $total = (clone $query)->count();
            $lastPage = max((int) ceil($total / $limit), 1);
            $page = min($page, $lastPage);

            return [
                'filters' => $this->pageFilters($filters, [
                    'type' => $filters['type'] ?? 'all',
                    'warehouse_id' => isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null,
                    'date_from' => $filters['date_from'] ?? null,
                    'date_to' => $filters['date_to'] ?? null,
                ], $limit, $page),
                'data' => $query
                    ->latest('id')
                    ->forPage($page, $limit)
                    ->get()
                    ->map(fn (StockMovement $movement): array => $this->movement($movement))
                    ->all(),
                'pagination' => $this->pagination($page, $limit, $total),
            ];
        } finally {
            PerformanceProbe::log('InventoryCenter movimientos pagina', $startedAt, 500, [
                'product_id' => $product->id,
                'search' => $filters['search'] ?? null,
                'type' => $filters['type'] ?? 'all',
            ]);
        }
    }

    public function stockByWarehousePage(Product $product): array
    {
        return PerformanceProbe::measure(
            'InventoryCenter stock por almacen pagina',
            fn (): array => [
                'data' => $this->stockByWarehouse($product),
            ],
            350,
            ['product_id' => $product->id]
        );
    }

    /**
     * Contexto completo de stock para el badge del POS:
     * - available: stock disponible en el warehouse seleccionado
     * - reserved: reservado en el warehouse (sales pendientes que retienen)
     * - other_warehouses: detalle por warehouse del tenant actual
     * - total_other: suma de available en otros warehouses
     *
     * Multi-tenancy: solo cuenta warehouses del tenant activo.
     */
    public function stockContext(Product $product, int $warehouseId): array
    {
        return PerformanceProbe::measure(
            'InventoryCenter stock context',
            function () use ($product, $warehouseId): array {
                $balance = StockBalance::query()
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouseId)
                    ->first();

                $otherRows = StockBalance::query()
                    ->with('warehouse:id,name,code')
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', '!=', $warehouseId)
                    ->get(['warehouse_id', 'quantity_available', 'quantity_reserved']);

                $otherWarehouses = $otherRows->map(fn ($row) => [
                    'warehouse_id' => $row->warehouse_id,
                    'warehouse_name' => $row->warehouse?->name,
                    'warehouse_code' => $row->warehouse?->code,
                    'available' => (float) $row->quantity_available,
                    'reserved' => (float) $row->quantity_reserved,
                ])->values()->all();

                $totalOther = (float) $otherRows->sum('quantity_available');

                return [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'available' => $balance ? (float) $balance->quantity_available : 0.0,
                    'reserved' => $balance ? (float) $balance->quantity_reserved : 0.0,
                    'other_warehouses' => $otherWarehouses,
                    'total_other' => $totalOther,
                    'total_all_warehouses' => (float) ($balance?->quantity_available ?? 0) + $totalOther,
                ];
            },
            200,
            ['product_id' => $product->id, 'warehouse_id' => $warehouseId]
        );
    }

    public function auditsPage(Product $product, array $filters): array
    {
        $startedAt = microtime(true);

        try {
            $limit = $this->limit($filters);
            $page = $this->page($filters);

            if (! Schema::hasTable('product_audits')) {
                return [
                    'filters' => $this->pageFilters($filters, [
                        'action' => $filters['action'] ?? 'all',
                    ], $limit, 1),
                    'data' => [],
                    'pagination' => $this->pagination(1, $limit, 0),
                ];
            }

            $query = ProductAudit::query()
                ->where('product_id', $product->id)
                ->with('creator');

            if (($filters['action'] ?? null) && $filters['action'] !== 'all') {
                $query->where('action', $filters['action']);
            }

            if ($search = $filters['search'] ?? null) {
                $query->whereHas('creator', function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $total = (clone $query)->count();
            $lastPage = max((int) ceil($total / $limit), 1);
            $page = min($page, $lastPage);

            return [
                'filters' => $this->pageFilters($filters, [
                    'action' => $filters['action'] ?? 'all',
                ], $limit, $page),
                'data' => $query
                    ->latest('id')
                    ->forPage($page, $limit)
                    ->get()
                    ->map(fn (ProductAudit $audit): array => $this->audit($audit))
                    ->all(),
                'pagination' => $this->pagination($page, $limit, $total),
            ];
        } finally {
            PerformanceProbe::log('InventoryCenter auditorias pagina', $startedAt, 500, [
                'product_id' => $product->id,
                'search' => $filters['search'] ?? null,
                'action' => $filters['action'] ?? 'all',
            ]);
        }
    }

    private function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'tracking_type' => $product->tracking_type,
            'base_price' => $product->base_price === null ? null : (float) $product->base_price,
            'sale_currency' => $product->sale_currency,
            'sale_exchange_rate_type_id' => $product->sale_exchange_rate_type_id,
            'sale_exchange_rate_type' => $product->saleExchangeRateType ? [
                'id' => $product->saleExchangeRateType->id,
                'code' => $product->saleExchangeRateType->code,
                'name' => $product->saleExchangeRateType->name,
                'is_default' => (bool) $product->saleExchangeRateType->is_default,
                'is_active' => (bool) $product->saleExchangeRateType->is_active,
            ] : null,
            'warranty_policy_id' => $product->warranty_policy_id,
            'warranty_policy' => $product->warrantyPolicy ? [
                'id' => $product->warrantyPolicy->id,
                'name' => $product->warrantyPolicy->name,
                'duration_days' => $product->warrantyPolicy->duration_days,
                'coverage_type' => $product->warrantyPolicy->coverage_type,
                'is_active' => (bool) $product->warrantyPolicy->is_active,
            ] : null,
            'is_active' => (bool) $product->is_active,
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }

    private function stockTotals(Product $product): array
    {
        $totals = StockBalance::query()
            ->where('product_id', $product->id)
            ->selectRaw('COALESCE(SUM(quantity_available), 0) as available')
            ->selectRaw('COALESCE(SUM(quantity_reserved), 0) as reserved')
            ->selectRaw('COALESCE(SUM(quantity_damaged), 0) as damaged')
            ->first();

        return [
            'available' => $this->roundStock((float) $totals->available),
            'reserved' => $this->roundStock((float) $totals->reserved),
            'damaged' => $this->roundStock((float) $totals->damaged),
        ];
    }

    private function stockByWarehouse(Product $product): array
    {
        return StockBalance::query()
            ->where('product_id', $product->id)
            ->with(['warehouse.branch'])
            ->orderBy('warehouse_id')
            ->get()
            ->map(fn (StockBalance $balance): array => [
                'warehouse_id' => $balance->warehouse_id,
                'warehouse_name' => $balance->warehouse?->name,
                'warehouse_code' => $balance->warehouse?->code,
                'branch_id' => $balance->warehouse?->branch_id,
                'branch_name' => $balance->warehouse?->branch?->name,
                'available' => $this->roundStock((float) $balance->quantity_available),
                'reserved' => $this->roundStock((float) $balance->quantity_reserved),
                'damaged' => $this->roundStock((float) $balance->quantity_damaged),
            ])
            ->all();
    }

    private function serials(Product $product): array
    {
        if (! $product->requiresSerializedTracking()) {
            return [
                'total' => 0,
                'items' => [],
            ];
        }

        $query = ProductUnit::query()
            ->where('product_id', $product->id);

        return [
            'total' => (clone $query)->count(),
            'items' => $query
                ->with('warehouse')
                ->orderBy('status')
                ->orderBy('serial_number')
                ->limit(50)
                ->get()
                ->map(fn (ProductUnit $unit): array => $this->serialUnit($unit))
                ->all(),
        ];
    }

    private function recentMovements(Product $product): array
    {
        return StockMovement::query()
            ->where('product_id', $product->id)
            ->with(['warehouse', 'creator'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (StockMovement $movement): array => $this->movement($movement))
            ->all();
    }

    private function recentAudits(Product $product): array
    {
        if (! Schema::hasTable('product_audits')) {
            return [];
        }

        return ProductAudit::query()
            ->where('product_id', $product->id)
            ->with('creator')
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(fn (ProductAudit $audit): array => $this->audit($audit))
            ->all();
    }

    private function roundStock(float $value): float
    {
        return round($value, 4);
    }

    private function serialUnit(ProductUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'serial_type' => $unit->serial_type,
            'serial_number' => $unit->serial_number,
            'status' => $unit->status,
            'warehouse_id' => $unit->warehouse_id,
            'warehouse_name' => $unit->warehouse?->name,
        ];
    }

    private function movement(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'type' => $movement->type,
            'quantity' => $this->roundStock((float) $movement->quantity),
            'unit_cost' => $movement->unit_cost === null ? null : (float) $movement->unit_cost,
            'reason' => $movement->reason,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'warehouse_id' => $movement->warehouse_id,
            'warehouse_name' => $movement->warehouse?->name,
            'created_by' => $movement->created_by,
            'created_by_name' => $movement->creator?->name,
            'created_at' => $movement->created_at?->toISOString(),
        ];
    }

    private function audit(ProductAudit $audit): array
    {
        return [
            'id' => $audit->id,
            'action' => $audit->action,
            'changes' => $audit->changes,
            'created_by' => $audit->created_by,
            'created_by_name' => $audit->creator?->name,
            'created_by_email' => $audit->creator?->email,
            'created_at' => $audit->created_at?->toISOString(),
        ];
    }

    private function limit(array $filters): int
    {
        return min(max((int) ($filters['limit'] ?? 24), 1), 100);
    }

    private function page(array $filters): int
    {
        return max((int) ($filters['page'] ?? 1), 1);
    }

    private function pageFilters(array $filters, array $extra = [], ?int $limit = null, ?int $page = null): array
    {
        return array_merge([
            'search' => $filters['search'] ?? null,
            'limit' => $limit ?? $this->limit($filters),
            'page' => $page ?? $this->page($filters),
        ], $extra);
    }

    private function pagination(int $page, int $limit, int $total): array
    {
        $lastPage = max((int) ceil($total / $limit), 1);

        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $total === 0 ? 0 : (($page - 1) * $limit) + 1,
            'to' => min($page * $limit, $total),
            'has_previous' => $page > 1,
            'has_next' => $page < $lastPage,
        ];
    }
}
