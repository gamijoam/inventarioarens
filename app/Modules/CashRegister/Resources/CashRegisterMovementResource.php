<?php

namespace App\Modules\CashRegister\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashRegisterMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'method' => $this->method,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'amount_base' => $this->amount_base,
            'amount_local' => $this->amount_local,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
