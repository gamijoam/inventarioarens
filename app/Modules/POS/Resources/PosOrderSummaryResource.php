<?php

namespace App\Modules\POS\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosOrderSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paidBase = (float) ($this->paid_base_amount ?? 0);
        $paidLocal = (float) ($this->paid_local_amount ?? 0);
        $totalBase = (float) ($this->total_base_amount ?? 0);
        $totalLocal = (float) ($this->total_local_amount ?? 0);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'cash_register_session_id' => $this->cash_register_session_id,
            'cashier_id' => $this->cashier_id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'total_base_amount' => $totalBase,
            'total_local_amount' => $totalLocal,
            'paid_base_amount' => $paidBase,
            'paid_local_amount' => $paidLocal,
            'remaining_base_amount' => round($totalBase - $paidBase, 4),
            'remaining_local_amount' => round($totalLocal - $paidLocal, 4),
            'items_count' => $this->whenLoaded('sale') ? (int) $this->sale->items->sum('quantity') : null,
            'opened_at' => $this->opened_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
        ];
    }
}
