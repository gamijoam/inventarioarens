<?php

namespace App\Modules\Promotions\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn (): ?string => $this->product?->name),
            'quantity' => (float) $this->quantity,
            'item_role' => $this->item_role,
            'sort_order' => $this->sort_order,
        ];
    }
}
