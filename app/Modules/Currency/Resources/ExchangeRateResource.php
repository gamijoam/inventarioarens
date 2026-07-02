<?php

namespace App\Modules\Currency\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type_code' => $this->whenLoaded('type', fn (): ?string => $this->type?->code),
            'exchange_rate_type_name' => $this->whenLoaded('type', fn (): ?string => $this->type?->name),
            'base_currency' => $this->base_currency,
            'quote_currency' => $this->quote_currency,
            'rate' => (float) $this->rate,
            'effective_at' => $this->effective_at?->toISOString(),
            'is_active' => (bool) $this->is_active,
            'source' => $this->source,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
