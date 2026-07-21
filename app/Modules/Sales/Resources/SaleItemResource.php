<?php

namespace App\Modules\Sales\Resources;

use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'sale_id' => $this->sale_id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->whenLoaded('warehouse', fn (): ?string => $this->warehouse?->name),
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn (): ?string => $this->product?->name),
            'price_list_id' => $this->price_list_id,
            'price_list_name' => $this->price_list_name,
            'quantity' => (float) $this->quantity,
            'sale_currency' => $this->sale_currency,
            'unit_price' => (float) $this->unit_price,
            'total_amount' => (float) $this->total_amount,
            'base_unit_price' => (float) $this->base_unit_price,
            'base_total_amount' => (float) $this->base_total_amount,
            'discount_type' => $this->discount_type,
            'discount_value' => (float) $this->discount_value,
            'discount_amount' => (float) $this->discount_amount,
            'discount_base_amount' => (float) $this->discount_base_amount,
            'discount_local_amount' => (float) $this->discount_local_amount,
            'discount_reason' => $this->discount_reason,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate === null ? null : (float) $this->exchange_rate,
            'stock_movement_id' => $this->stock_movement_id,
            'product_unit_ids' => $this->product_unit_ids,
            'serial_units' => $this->serialUnits($request),
            'warranty_policy_id' => $this->warranty_policy_id,
            'warranty_policy_name' => $this->warranty_policy_name,
            'warranty_duration_days' => $this->warranty_duration_days,
            'warranty_coverage_type' => $this->warranty_coverage_type,
            'warranty_conditions' => $this->warranty_conditions,
            'warranty_starts_at' => $this->warranty_starts_at?->toISOString(),
            'warranty_expires_at' => $this->warranty_expires_at?->toISOString(),
        ];
    }

    /**
     * @return list<array{id:int,serial_type:string,serial_number:string,status:string}>
     */
    private function serialUnits(Request $request): array
    {
        $unitIds = $this->product_unit_ids ?? [];

        if ($unitIds === []) {
            return [];
        }

        $lookup = $request->attributes->get('serial_units_lookup');

        if (is_array($lookup)) {
            $result = [];
            foreach ($unitIds as $unitId) {
                if (isset($lookup[$unitId])) {
                    $result[] = $lookup[$unitId];
                }
            }

            return $result;
        }

        return ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->get()
            ->sortBy(fn (ProductUnit $unit): int => array_search($unit->id, $unitIds, true))
            ->map(fn (ProductUnit $unit): array => [
                'id' => $unit->id,
                'serial_type' => $unit->serial_type,
                'serial_number' => $unit->serial_number,
                'status' => $unit->status,
            ])
            ->values()
            ->all();
    }
}
