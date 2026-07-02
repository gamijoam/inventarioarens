<?php

namespace App\Modules\POS\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'amount_base' => $this->amount_base,
            'amount_local' => $this->amount_local,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate,
            'status' => $this->status,
            'reference' => $this->reference,
            'external_provider' => $this->external_provider,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
