<?php

namespace App\Modules\AccountsReceivable\Resources;

use App\Modules\Customers\Resources\CustomerResource;
use App\Modules\Sales\Resources\SaleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountsReceivableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'sale_id' => $this->sale_id,
            'sale' => SaleResource::make($this->whenLoaded('sale')),
            'status' => $this->status,
            'document_number' => $this->document_number,
            'currency' => $this->currency,
            'original_base_amount' => $this->original_base_amount,
            'original_local_amount' => $this->original_local_amount,
            'returned_base_amount' => $this->returned_base_amount,
            'returned_local_amount' => $this->returned_local_amount,
            'collected_base_amount' => $this->collected_base_amount,
            'collected_local_amount' => $this->collected_local_amount,
            'balance_base_amount' => $this->balance_base_amount,
            'balance_local_amount' => $this->balance_local_amount,
            'due_date' => $this->due_date?->toDateString(),
            'opened_at' => $this->opened_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'payments' => AccountsReceivablePaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
