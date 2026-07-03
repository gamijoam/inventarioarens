<?php

namespace App\Modules\Warranties\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarrantyClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'sale_id' => $this->sale_id,
            'sale_item_id' => $this->sale_item_id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn (): ?string => $this->product?->name),
            'product_unit_id' => $this->product_unit_id,
            'product_unit_serial' => $this->whenLoaded('productUnit', fn (): ?string => $this->productUnit?->serial_number),
            'replacement_product_unit_id' => $this->replacement_product_unit_id,
            'replacement_product_unit_serial' => $this->whenLoaded('replacementProductUnit', fn (): ?string => $this->replacementProductUnit?->serial_number),
            'status' => $this->status,
            'quantity' => (float) $this->quantity,
            'issue_description' => $this->issue_description,
            'received_notes' => $this->received_notes,
            'diagnosis' => $this->diagnosis,
            'resolution_type' => $this->resolution_type,
            'resolution_notes' => $this->resolution_notes,
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
            'replacement_stock_movement_id' => $this->replacement_stock_movement_id,
            'received_by' => $this->received_by,
            'reviewed_by' => $this->reviewed_by,
            'delivered_by' => $this->delivered_by,
            'resolved_by' => $this->resolved_by,
            'received_at' => $this->received_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'warranty_policy_name' => $this->whenLoaded('saleItem', fn (): ?string => $this->saleItem?->warranty_policy_name),
            'warranty_expires_at' => $this->whenLoaded('saleItem', fn (): ?string => $this->saleItem?->warranty_expires_at?->toISOString()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
