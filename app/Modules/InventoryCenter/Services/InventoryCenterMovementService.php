<?php

namespace App\Modules\InventoryCenter\Services;

use App\Modules\Inventory\Models\StockMovement;
use App\Support\Performance\PerformanceProbe;
use Illuminate\Database\Eloquent\Builder;

class InventoryCenterMovementService
{
    public function page(array $filters): array
    {
        $startedAt = microtime(true);

        try {
            $limit = $this->limit($filters);
            $page = $this->pageNumber($filters);
            $query = StockMovement::query()
                ->with(['product', 'warehouse.branch', 'creator']);

            if ($search = $filters['search'] ?? null) {
                $normalizedSearch = mb_strtolower($search);

                $query->where(function (Builder $query) use ($normalizedSearch): void {
                    $query
                        ->whereRaw('LOWER(reason) like ?', ["%{$normalizedSearch}%"])
                        ->orWhereRaw('LOWER(reference_type) like ?', ["%{$normalizedSearch}%"])
                        ->orWhereHas('product', function (Builder $query) use ($normalizedSearch): void {
                            $query
                                ->whereRaw('LOWER(name) like ?', ["%{$normalizedSearch}%"])
                                ->orWhereRaw('LOWER(sku) like ?', ["%{$normalizedSearch}%"]);
                        });
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
                'filters' => [
                    'search' => $filters['search'] ?? null,
                    'type' => $filters['type'] ?? 'all',
                    'warehouse_id' => isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null,
                    'date_from' => $filters['date_from'] ?? null,
                    'date_to' => $filters['date_to'] ?? null,
                    'limit' => $limit,
                    'page' => $page,
                ],
                'data' => $query
                    ->latest('id')
                    ->forPage($page, $limit)
                    ->get()
                    ->map(fn (StockMovement $movement): array => $this->movement($movement))
                    ->all(),
                'pagination' => $this->pagination($page, $limit, $total),
            ];
        } finally {
            PerformanceProbe::log('InventoryCenter movimientos globales pagina', $startedAt, 500, [
                'search' => $filters['search'] ?? null,
                'type' => $filters['type'] ?? 'all',
                'warehouse_id' => $filters['warehouse_id'] ?? null,
            ]);
        }
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
            'product_id' => $movement->product_id,
            'product_name' => $movement->product?->name,
            'product_sku' => $movement->product?->sku,
            'warehouse_id' => $movement->warehouse_id,
            'warehouse_name' => $movement->warehouse?->name,
            'warehouse_code' => $movement->warehouse?->code,
            'branch_id' => $movement->warehouse?->branch_id,
            'branch_name' => $movement->warehouse?->branch?->name,
            'created_by' => $movement->created_by,
            'created_by_name' => $movement->creator?->name,
            'created_by_email' => $movement->creator?->email,
            'created_at' => $movement->created_at?->toISOString(),
        ];
    }

    private function roundStock(float $value): float
    {
        return round($value, 4);
    }

    private function limit(array $filters): int
    {
        return min(max((int) ($filters['limit'] ?? 50), 1), 100);
    }

    private function pageNumber(array $filters): int
    {
        return max((int) ($filters['page'] ?? 1), 1);
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
