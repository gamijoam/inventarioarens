<?php

namespace App\Modules\AccountsPayable\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountsPayablePaymentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'accounts_payable_id' => $this->accounts_payable_id,
            'accounts_payable_payment_id' => $this->accounts_payable_payment_id,
            'account' => AccountsPayableResource::make($this->whenLoaded('account')),
            'payment' => AccountsPayablePaymentResource::make($this->whenLoaded('payment')),
            'status' => $this->status,
            'payment_currency' => $this->payment_currency,
            'amount' => $this->amount,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate,
            'amount_base' => $this->amount_base,
            'amount_local' => $this->amount_local,
            'method' => $this->method,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'scheduled_for' => $this->scheduled_for?->toISOString(),
            'cash_register_session_id' => $this->cash_register_session_id,
            'prepared_by' => $this->prepared_by,
            'approved_by' => $this->approved_by,
            'rejected_by' => $this->rejected_by,
            'cancelled_by' => $this->cancelled_by,
            'executed_by' => $this->executed_by,
            'prepared_at' => $this->prepared_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'executed_at' => $this->executed_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
