<?php

namespace App\Modules\POS\Resources;

use App\Modules\Sales\Resources\SaleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'status' => $this->status,
            'cashier_id' => $this->cashier_id,
            'customer_name' => $this->customer_name,
            'total_base_amount' => $this->total_base_amount,
            'total_local_amount' => $this->total_local_amount,
            'paid_base_amount' => $this->paid_base_amount,
            'paid_local_amount' => $this->paid_local_amount,
            'opened_at' => $this->opened_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'sale' => SaleResource::make($this->whenLoaded('sale')),
            'payments' => PosPaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
