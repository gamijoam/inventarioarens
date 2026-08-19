<?php

namespace App\Modules\Quotations\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quotation_id' => $this->quotation_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'product_name' => $this->product_name,
            'sku' => $this->sku,
            'quantity' => (float) $this->quantity,
            'price_list_id' => $this->price_list_id,
            'unit_price_base' => (float) $this->unit_price_base,
            'unit_price_local' => (float) $this->unit_price_local,
            'discount_base' => (float) $this->discount_base,
            'discount_local' => (float) $this->discount_local,
            'total_base' => (float) $this->total_base,
            'total_local' => (float) $this->total_local,
        ];
    }
}
