<?php

namespace App\Modules\Fiscal\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiscalDocumentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_item_id' => $this->sale_item_id,
            'quantity' => (float) $this->quantity,
            'sale_currency' => $this->sale_currency,
            'unit_price' => (float) $this->unit_price,
            'total_amount' => (float) $this->total_amount,
            'base_unit_price' => (float) $this->base_unit_price,
            'base_total_amount' => (float) $this->base_total_amount,
            'local_total_amount' => (float) $this->local_total_amount,
            'product_snapshot' => $this->product_snapshot,
            'warehouse_snapshot' => $this->warehouse_snapshot,
            'commercial_snapshot' => $this->commercial_snapshot,
            'fiscal_snapshot' => $this->fiscal_snapshot,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
