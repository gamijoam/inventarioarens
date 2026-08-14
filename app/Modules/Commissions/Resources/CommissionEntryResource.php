<?php

namespace App\Modules\Commissions\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_uuid' => $this->entry_uuid,
            'sale_id' => $this->sale_id,
            'pos_order_id' => $this->pos_order_id,
            'sale_item_id' => $this->sale_item_id,
            'adjustment_reason' => $this->adjustment_reason,
            'beneficiary_role' => $this->beneficiary_role,
            'beneficiary' => $this->whenLoaded('beneficiary', fn () => [
                'id' => $this->beneficiary->id,
                'name' => $this->beneficiary->name,
                'email' => $this->beneficiary->email,
            ]),
            'entry_type' => $this->entry_type,
            'plan_name_snapshot' => $this->plan_name_snapshot,
            'percentage_snapshot' => $this->percentage_snapshot,
            'sale_currency' => $this->sale_currency,
            'source_amount' => $this->source_amount,
            'eligible_base_amount' => $this->eligible_base_amount,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate,
            'commission_base_amount' => $this->commission_base_amount,
            'status' => $this->status,
            'approved_at' => $this->approved_at?->toJSON(),
            'earned_at' => $this->earned_at?->toJSON(),
            'available_at' => $this->available_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
