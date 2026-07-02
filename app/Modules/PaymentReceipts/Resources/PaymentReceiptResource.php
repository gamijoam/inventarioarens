<?php

namespace App\Modules\PaymentReceipts\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'receipt_number' => $this->receipt_number,
            'type' => $this->type,
            'status' => $this->status,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'accounts_receivable_payment_id' => $this->accounts_receivable_payment_id,
            'accounts_payable_payment_id' => $this->accounts_payable_payment_id,
            'party_type' => $this->party_type,
            'party_id' => $this->party_id,
            'party_name' => $this->party_name,
            'party_document_type' => $this->party_document_type,
            'party_document_number' => $this->party_document_number,
            'payment_currency' => $this->payment_currency,
            'amount' => $this->amount,
            'amount_base' => $this->amount_base,
            'amount_local' => $this->amount_local,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate,
            'method' => $this->method,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'issued_by' => $this->issued_by,
            'issued_at' => $this->issued_at?->toISOString(),
            'voided_by' => $this->voided_by,
            'voided_at' => $this->voided_at?->toISOString(),
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
