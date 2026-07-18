<?php

namespace App\Modules\SalesReturns\Resources;

use App\Modules\Sales\Resources\SaleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'status' => $this->status,
            'reason' => $this->reason,
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'reviewed_by' => $this->reviewed_by,
            'reviewed_by_name' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'processed_by' => $this->processed_by,
            'processed_by_name' => $this->whenLoaded('processor', fn () => $this->processor?->name),
            'processed_at' => $this->processed_at?->toISOString(),
            'cancelled_by' => $this->cancelled_by,
            'cancelled_by_name' => $this->whenLoaded('canceller', fn () => $this->canceller?->name),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'refund_currency' => $this->refund_currency,
            'refund_amount' => $this->refund_amount === null ? null : (float) $this->refund_amount,
            'refund_exchange_rate_type_id' => $this->refund_exchange_rate_type_id,
            'refund_exchange_rate_type_code' => $this->refund_exchange_rate_type_code,
            'refund_exchange_rate' => $this->refund_exchange_rate === null ? null : (float) $this->refund_exchange_rate,
            'refund_amount_base' => $this->refund_amount_base === null ? null : (float) $this->refund_amount_base,
            'refund_amount_local' => $this->refund_amount_local === null ? null : (float) $this->refund_amount_local,
            'refund_method' => $this->refund_method,
            'refund_reference' => $this->refund_reference,
            'refund_cash_register_movement_id' => $this->refund_cash_register_movement_id,
            'refund_financial_adjustment_id' => $this->refund_financial_adjustment_id,
            'process_notes' => $this->process_notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'sale' => SaleResource::make($this->whenLoaded('sale')),
            'items' => SalesReturnItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
