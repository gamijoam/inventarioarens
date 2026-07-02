<?php

namespace App\Modules\AccountsReceivable\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountsReceivablePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
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
            'created_by' => $this->created_by,
            'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
