<?php

namespace App\Modules\Commissions\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'settlement_uuid' => $this->settlement_uuid,
            'status' => $this->status,
            'payment_currency' => $this->payment_currency,
            'total_base_amount' => $this->total_base_amount,
            'total_local_amount' => $this->total_local_amount,
            'payment_amount' => $this->payment_amount,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'beneficiary' => $this->whenLoaded('beneficiary', fn () => [
                'id' => $this->beneficiary->id,
                'name' => $this->beneficiary->name,
                'email' => $this->beneficiary->email,
            ]),
            'entry_uuids' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => $item->entry?->entry_uuid)->filter()->values()),
            'paid_at' => $this->paid_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
