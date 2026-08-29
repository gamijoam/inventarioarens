<?php

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class CrmAvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $balances = $this->whenLoaded('stockBalances', fn () => $this->stockBalances);
        $balances = $balances instanceof MissingValue ? collect() : $balances;
        $grouped = $balances->groupBy('warehouse_id');
        $warehouses = $grouped->map(function ($rows, $warehouseId): array {
            $warehouse = $rows->first()->warehouse;

            return [
                'warehouse_id' => (int) $warehouseId,
                'warehouse_code' => $warehouse?->code,
                'warehouse_name' => $warehouse?->name,
                'branch_id' => $warehouse?->branch_id,
                'branch_code' => $warehouse?->branch?->code,
                'branch_name' => $warehouse?->branch?->name,
                'available_quantity' => $this->round((float) $rows->sum('quantity_available')),
                'reserved_quantity' => $this->round((float) $rows->sum('quantity_reserved')),
                'damaged_quantity' => $this->round((float) $rows->sum('quantity_damaged')),
                'as_of' => $this->maxTimestamp($rows->pluck('updated_at')->all()),
            ];
        })->values()->all();

        return [
            'product_id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'unit_of_measure' => $this->unit_of_measure,
            'tracking_type' => $this->tracking_type,
            'available_quantity' => $this->round((float) $balances->sum('quantity_available')),
            'reserved_quantity' => $this->round((float) $balances->sum('quantity_reserved')),
            'damaged_quantity' => $this->round((float) $balances->sum('quantity_damaged')),
            'as_of' => $this->maxTimestamp($balances->pluck('updated_at')->all()),
            'stock_source' => 'stock_balances',
            'last_sync_at' => $this->crm_last_sync_at?->toISOString(),
            'warehouses' => $warehouses,
        ];
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

        $latest = collect($timestamps)->map(fn ($timestamp) => $timestamp instanceof \DateTimeInterface
            ? $timestamp
            : new \DateTimeImmutable((string) $timestamp))->sort()->last();

        return $latest?->format(DATE_ATOM);
    }
}
