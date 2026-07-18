<?php

namespace App\Modules\Purchases\Resources;

use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\Suppliers\Resources\SupplierResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'status' => $this->status,
            'document_number' => $this->document_number,
            'issued_at' => $this->issued_at?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'purchase_currency' => $this->purchase_currency,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate,
            'total_base_amount' => $this->total_base_amount,
            'total_local_amount' => $this->total_local_amount,
            'received_base_amount' => $this->received_base_amount,
            'received_local_amount' => $this->received_local_amount,
            'items_count' => $this->items_count ?? $this->whenCounted('items'),
            'created_by' => $this->created_by,
            'received_at' => $this->received_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'supplier' => SupplierResource::make($this->whenLoaded('supplier')),
            'items' => PurchaseItemResource::collection($this->whenLoaded('items')),
            'account_payable' => $this->whenLoaded('accountPayable', fn (): array => [
                'id' => $this->accountPayable?->id,
                'status' => $this->accountPayable?->status,
                'document_number' => $this->accountPayable?->document_number,
                'original_base_amount' => $this->accountPayable?->original_base_amount,
                'original_local_amount' => $this->accountPayable?->original_local_amount,
                'paid_base_amount' => $this->accountPayable?->paid_base_amount,
                'paid_local_amount' => $this->accountPayable?->paid_local_amount,
                'balance_base_amount' => $this->accountPayable?->balance_base_amount,
                'balance_local_amount' => $this->accountPayable?->balance_local_amount,
                'due_date' => $this->accountPayable?->due_date?->toDateString(),
                'paid_at' => $this->accountPayable?->paid_at?->toISOString(),
                'is_open' => in_array($this->accountPayable?->status, [
                    AccountsPayable::STATUS_PENDING,
                    AccountsPayable::STATUS_PARTIAL,
                    AccountsPayable::STATUS_OVERDUE,
                ], true),
            ]),
            'price_review_items' => $this->getAttribute('price_review_items') ?? [],
        ];
    }
}
