<?php

namespace App\Modules\Sales\Resources;

use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCosts = (bool) ($request->user()?->can('finance.costs.view') ?? false);
        $resolvedUnitCost = null;
        $baseTotalCost = null;
        $profitBaseAmount = null;
        $profitMarginPercent = null;

        if ($canViewCosts) {
            $costVal = $this->base_unit_cost;
            if ($costVal === null && $this->relationLoaded('product') && $this->product) {
                $costVal = $this->product->last_purchase_cost ?? $this->product->average_cost;
            }
            if ($costVal !== null) {
                $resolvedUnitCost = round((float) $costVal, 4);
                $baseTotalCost = round($resolvedUnitCost * (float) $this->quantity, 4);
                $itemTotalBase = (float) $this->base_total_amount;
                $profitBaseAmount = round($itemTotalBase - $baseTotalCost, 4);
                $profitMarginPercent = $itemTotalBase > 0 ? round(($profitBaseAmount / $itemTotalBase) * 100, 2) : 0.0;
            }
        }

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'sale_id' => $this->sale_id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->whenLoaded('warehouse', fn (): ?string => $this->warehouse?->name),
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn (): ?string => $this->product?->name),
            'product_variant_id' => $this->product_variant_id,
            'product_variant' => $this->whenLoaded('variant', fn (): ?array => $this->variant === null ? null : [
                'id' => $this->variant->id,
                'color' => $this->variant->color,
                'color_hex' => $this->variant->color_hex,
                'sku' => $this->variant->sku_variant,
                'barcode' => $this->variant->barcode_variant,
            ]),
            'price_list_id' => $this->price_list_id,
            'price_list_name' => $this->price_list_name,
            'promotion_id' => $this->promotion_id,
            'promotion_code' => $this->promotion_code,
            'promotion_name' => $this->promotion_name,
            'promotion_benefit_type' => $this->promotion_benefit_type,
            'promotion_price_usd' => $this->promotion_price_usd === null ? null : (float) $this->promotion_price_usd,
            'promotion_discount_percent' => $this->promotion_discount_percent === null ? null : (float) $this->promotion_discount_percent,
            'promotion_discount_amount_usd' => $this->promotion_discount_amount_usd === null ? null : (float) $this->promotion_discount_amount_usd,
            'promotion_adjustment_base_amount' => (float) $this->promotion_adjustment_base_amount,
            'promotion_adjustment_local_amount' => (float) $this->promotion_adjustment_local_amount,
            'quantity' => (float) $this->quantity,
            'sale_currency' => $this->sale_currency,
            'unit_price' => (float) $this->unit_price,
            'total_amount' => (float) $this->total_amount,
            'base_unit_price' => (float) $this->base_unit_price,
            'base_total_amount' => (float) $this->base_total_amount,
            'base_unit_cost' => $resolvedUnitCost,
            'base_total_cost' => $baseTotalCost,
            'profit_base_amount' => $profitBaseAmount,
            'profit_margin_percent' => $profitMarginPercent,
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
