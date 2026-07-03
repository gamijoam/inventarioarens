<?php

namespace App\Modules\FinancialAdjustments\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'document_number' => $this->document_number,
            'account_type' => $this->account_type,
            'accounts_receivable_id' => $this->accounts_receivable_id,
            'accounts_payable_id' => $this->accounts_payable_id,
            'status' => $this->status,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate,
            'amount_base' => $this->amount_base,
            'amount_local' => $this->amount_local,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'applied_at' => $this->applied_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
