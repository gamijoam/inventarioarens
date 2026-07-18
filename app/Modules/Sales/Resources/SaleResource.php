<?php

namespace App\Modules\Sales\Resources;

use App\Modules\Customers\Resources\CustomerResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'total_base_amount' => (float) $this->total_base_amount,
            'total_local_amount' => (float) $this->total_local_amount,
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'items_count' => $this->items_count ?? $this->whenLoaded('items', fn () => $this->items->count()),
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'pos_order' => $this->whenLoaded('posOrder', fn () => $this->posOrder ? [
                'id' => $this->posOrder->id,
                'status' => $this->posOrder->status,
                'cashier_id' => $this->posOrder->cashier_id,
                'cashier_name' => $this->posOrder->cashier?->name,
                'cash_register_session_id' => $this->posOrder->cash_register_session_id,
                'paid_at' => $this->posOrder->paid_at?->toISOString(),
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
