<?php

namespace App\Modules\SalesReversals\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleReversalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'reason' => $this->reason,
            'original_sale_id' => $this->sale_id,
            'original_pos_order_id' => $this->pos_order_id,
            'cash_register_session_id' => $this->cash_register_session_id,
            'original_paid_at' => $this->original_paid_at?->toISOString(),
            'effective_at' => $this->effective_at?->toISOString(),
            'reversed_base_amount' => (float) $this->reversed_base_amount,
            'reversed_local_amount' => (float) $this->reversed_local_amount,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
