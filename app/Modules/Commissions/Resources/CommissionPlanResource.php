<?php

namespace App\Modules\Commissions\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'beneficiary_role' => $this->beneficiary_role,
            'percentage' => $this->percentage,
            'conversion_policy' => $this->conversion_policy,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type' => $this->whenLoaded('exchangeRateType', fn () => $this->exchangeRateType ? [
                'id' => $this->exchangeRateType->id,
                'code' => $this->exchangeRateType->code,
                'name' => $this->exchangeRateType->name,
            ] : null),
            'credit_policy' => $this->credit_policy,
            'maturation_days' => $this->maturation_days,
            'allow_self_stacking' => $this->allow_self_stacking,
            'include_combos' => (bool) $this->include_combos,
            'include_discounts' => (bool) $this->include_discounts,
            'is_active' => $this->is_active,
            'starts_at' => $this->starts_at?->toJSON(),
            'ends_at' => $this->ends_at?->toJSON(),
            'assignments' => $this->whenLoaded('assignments', fn () => $this->assignments->map(fn ($assignment) => [
                'id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'is_active' => $assignment->is_active,
                'starts_at' => $assignment->starts_at?->toJSON(),
                'ends_at' => $assignment->ends_at?->toJSON(),
                'user' => $assignment->user ? [
                    'id' => $assignment->user->id,
                    'name' => $assignment->user->name,
                    'email' => $assignment->user->email,
                ] : null,
            ])),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
