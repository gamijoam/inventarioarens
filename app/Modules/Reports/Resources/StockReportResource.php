<?php

namespace App\Modules\Reports\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'sku' => $this->product?->sku,
            'quantity_available' => (float) $this->quantity_available,
            'quantity_reserved' => (float) $this->quantity_reserved,
            'quantity_damaged' => (float) $this->quantity_damaged,
        ];
    }
}
