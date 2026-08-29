<?php

namespace App\Modules\SalesReturns\Resources;

use App\Modules\Products\Resources\ProductResource;
use App\Modules\Warehouses\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sales_return_id' => $this->sales_return_id,
            'sale_item_id' => $this->sale_item_id,
            'warehouse_id' => $this->warehouse_id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'refundable_base_amount' => $this->refundableBaseAmount(),
            'fiscal_tax_source' => $this->fiscal_tax_source,
            'fiscal_tax_override_code' => $this->fiscal_tax_override_code,
            'fiscal_tax_code' => $this->fiscal_tax_code,
            'fiscal_tax_name' => $this->fiscal_tax_name,
            'fiscal_tax_category' => $this->fiscal_tax_category,
            'fiscal_tax_rate' => $this->fiscal_tax_rate === null ? null : (float) $this->fiscal_tax_rate,
            'fiscal_prices_include_tax' => (bool) $this->fiscal_prices_include_tax,
            'fiscal_taxable_base_amount' => (float) $this->fiscal_taxable_base_amount,
            'fiscal_taxable_local_amount' => (float) $this->fiscal_taxable_local_amount,
            'fiscal_exempt_base_amount' => (float) $this->fiscal_exempt_base_amount,
            'fiscal_exempt_local_amount' => (float) $this->fiscal_exempt_local_amount,
            'fiscal_exonerated_base_amount' => (float) $this->fiscal_exonerated_base_amount,
            'fiscal_exonerated_local_amount' => (float) $this->fiscal_exonerated_local_amount,
            'fiscal_non_taxable_base_amount' => (float) $this->fiscal_non_taxable_base_amount,
            'fiscal_non_taxable_local_amount' => (float) $this->fiscal_non_taxable_local_amount,
            'fiscal_tax_base_amount' => (float) $this->fiscal_tax_base_amount,
            'fiscal_tax_local_amount' => (float) $this->fiscal_tax_local_amount,
            'fiscal_total_base_amount' => (float) $this->fiscal_total_base_amount,
            'fiscal_total_local_amount' => (float) $this->fiscal_total_local_amount,
            'fiscal_snapshot_at' => $this->fiscal_snapshot_at?->toISOString(),
            'product_unit_ids' => $this->product_unit_ids,
            'stock_movement_id' => $this->stock_movement_id,
            'condition' => $this->condition,
            'reason' => $this->reason,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
        ];
    }

    private function refundableBaseAmount(): ?float
    {
        if (! $this->relationLoaded('saleItem') || ! $this->saleItem || (float) $this->saleItem->quantity <= 0.0) {
            return null;
        }

        if ($this->fiscal_snapshot_at !== null) {
            return (float) $this->fiscal_total_base_amount;
        }

        return round(((float) $this->saleItem->base_total_amount / (float) $this->saleItem->quantity) * (float) $this->quantity, 4);
    }
}
