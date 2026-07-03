<?php

namespace App\Modules\AccountsPayable\Resources;

use App\Modules\Purchases\Resources\PurchaseOrderResource;
use App\Modules\Suppliers\Resources\SupplierResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountsPayableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier' => SupplierResource::make($this->whenLoaded('supplier')),
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => PurchaseOrderResource::make($this->whenLoaded('purchaseOrder')),
            'status' => $this->status,
            'document_number' => $this->document_number,
            'currency' => $this->currency,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate,
            'original_base_amount' => $this->original_base_amount,
            'original_local_amount' => $this->original_local_amount,
            'returned_base_amount' => $this->returned_base_amount,
            'returned_local_amount' => $this->returned_local_amount,
            'paid_base_amount' => $this->paid_base_amount,
            'paid_local_amount' => $this->paid_local_amount,
            'adjusted_base_amount' => $this->adjusted_base_amount,
            'adjusted_local_amount' => $this->adjusted_local_amount,
            'balance_base_amount' => $this->balance_base_amount,
            'balance_local_amount' => $this->balance_local_amount,
            'due_date' => $this->due_date?->toDateString(),
            'opened_at' => $this->opened_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'payments' => AccountsPayablePaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
