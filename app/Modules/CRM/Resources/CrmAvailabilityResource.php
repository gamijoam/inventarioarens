<?php

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class CrmAvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $balances = $this->resource->relationLoaded('stockBalances')
            ? $this->stockBalances
            : collect();
        $selectedWarehouseIds = $this->resource->getAttribute('crm_selected_warehouse_ids');
        $selectedBalances = $selectedWarehouseIds === null
            ? $balances
            : $balances->whereIn('warehouse_id', $selectedWarehouseIds);
        $asOf = $this->maxTimestamp($selectedBalances->pluck('updated_at')->all());
        $available = $this->round((float) $selectedBalances->sum('quantity_available'));
        $requestedBranch = $this->resource->getAttribute('crm_requested_branch');
        $requestedWarehouse = $this->resource->getAttribute('crm_requested_warehouse');
        $includeAlternatives = (bool) $this->resource->getAttribute('crm_include_alternatives');
        $alternatives = $includeAlternatives && $requestedBranch !== null
            ? $this->alternatives($balances, (int) $requestedBranch['id'])
            : [];
        $hasAvailability = $available > 0;
        $isStale = $this->isStale($asOf);

        return [
            'product_id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'sale_price' => $this->base_price === null ? null : (float) $this->base_price,
            'sale_currency' => $this->sale_currency,
            'unit_of_measure' => $this->unit_of_measure,
            'tracking_type' => $this->tracking_type,
            'branch_id' => $requestedBranch['id'] ?? null,
            'branch_code' => $requestedBranch['code'] ?? null,
            'branch_name' => $requestedBranch['name'] ?? null,
            'branch_slug' => $requestedBranch['slug'] ?? null,
            'tenant_id' => $requestedBranch['tenant_id'] ?? null,
            'tenant_slug' => $requestedBranch['tenant_slug'] ?? null,
            'warehouse_id' => $requestedWarehouse['id'] ?? null,
            'warehouse_code' => $requestedWarehouse['code'] ?? null,
            'warehouse_name' => $requestedWarehouse['name'] ?? null,
            'available_quantity' => $available,
            'reserved_quantity' => $this->round((float) $selectedBalances->sum('quantity_reserved')),
            'damaged_quantity' => $this->round((float) $selectedBalances->sum('quantity_damaged')),
            'has_availability' => $hasAvailability,
            'as_of' => $asOf,
            'is_stale' => $isStale,
            'stock_source' => 'stock_balances',
            'last_sync_at' => $this->resource->crm_last_sync_at?->toISOString(),
            'requested_location' => $requestedBranch === null ? null : [
                'branch_id' => $requestedBranch['id'],
                'branch_code' => $requestedBranch['code'],
                'branch_name' => $requestedBranch['name'],
                'branch_slug' => $requestedBranch['slug'],
                'tenant_id' => $requestedBranch['tenant_id'],
                'tenant_slug' => $requestedBranch['tenant_slug'],
                'warehouse_id' => $requestedWarehouse['id'] ?? null,
                'warehouse_code' => $requestedWarehouse['code'] ?? null,
                'warehouse_name' => $requestedWarehouse['name'] ?? null,
                'available_quantity' => $available,
                'reserved_quantity' => $this->round((float) $selectedBalances->sum('quantity_reserved')),
                'damaged_quantity' => $this->round((float) $selectedBalances->sum('quantity_damaged')),
                'has_availability' => $hasAvailability,
                'as_of' => $asOf,
                'is_stale' => $isStale,
            ],
            'alternatives' => $alternatives,
            'warehouses' => $this->warehouseRows($balances),
        ];
    }

    private function alternatives($balances, int $selectedBranchId): array
    {
        return $balances
            ->filter(fn ($balance): bool => (int) $balance->warehouse?->branch_id !== $selectedBranchId)
            ->groupBy(fn ($balance) => $balance->warehouse?->branch_id)
            ->map(function ($rows, $branchId): ?array {
                $available = $this->round((float) $rows->sum('quantity_available'));
                if ($available <= 0) {
                    return null;
                }

                $branch = $rows->first()->warehouse?->branch;
                $asOf = $this->maxTimestamp($rows->pluck('updated_at')->all());

                return [
                    'branch_id' => (int) $branchId,
                    'branch_code' => $branch?->code,
                    'branch_name' => $branch?->name,
                    'branch_slug' => $branch?->slug,
                    'tenant_id' => $branch?->tenant_id,
                    'tenant_slug' => $branch?->tenant?->slug,
                    'available_quantity' => $available,
                    'reserved_quantity' => $this->round((float) $rows->sum('quantity_reserved')),
                    'damaged_quantity' => $this->round((float) $rows->sum('quantity_damaged')),
                    'as_of' => $asOf,
                    'is_stale' => $this->isStale($asOf),
                ];
            })
            ->filter()
            ->sortBy('branch_name')
            ->values()
            ->all();
    }

    private function warehouseRows($balances): array
    {
        return $balances
            ->groupBy('warehouse_id')
            ->map(function ($rows, $warehouseId): array {
                $warehouse = $rows->first()->warehouse;

                return [
                    'warehouse_id' => (int) $warehouseId,
                    'warehouse_code' => $warehouse?->code,
                    'warehouse_name' => $warehouse?->name,
                    'branch_id' => $warehouse?->branch_id,
                    'branch_code' => $warehouse?->branch?->code,
                    'branch_name' => $warehouse?->branch?->name,
                    'branch_slug' => $warehouse?->branch?->slug,
                    'tenant_id' => $warehouse?->branch?->tenant_id,
                    'tenant_slug' => $warehouse?->branch?->tenant?->slug,
                    'available_quantity' => $this->round((float) $rows->sum('quantity_available')),
                    'reserved_quantity' => $this->round((float) $rows->sum('quantity_reserved')),
                    'damaged_quantity' => $this->round((float) $rows->sum('quantity_damaged')),
                    'as_of' => $this->maxTimestamp($rows->pluck('updated_at')->all()),
                    'is_stale' => $this->isStale($this->maxTimestamp($rows->pluck('updated_at')->all())),
                ];
            })
            ->sortBy('warehouse_name')
            ->values()
            ->all();
    }

    private function round(float $value): float
    {
        return round($value, 4);
    }

    private function maxTimestamp(array $timestamps): ?string
    {
        $timestamps = array_filter($timestamps);
        if ($timestamps === []) {
            return null;
        }

        $latest = collect($timestamps)
            ->map(fn ($timestamp) => $timestamp instanceof \DateTimeInterface
                ? $timestamp
                : new \DateTimeImmutable((string) $timestamp))
            ->sort()
            ->last();

        return $latest?->format(DATE_ATOM);
    }

    private function isStale(?string $asOf): ?bool
    {
        if ($asOf === null) {
            return null;
        }

        $threshold = max(1, (int) config('services.crm.stock_stale_after_minutes', 30));

        return Carbon::parse($asOf)->lt(now()->subMinutes($threshold));
    }
}
