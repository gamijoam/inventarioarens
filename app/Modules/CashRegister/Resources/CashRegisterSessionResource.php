<?php

namespace App\Modules\CashRegister\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashRegisterSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'branch_id' => $this->branch_id,
            'cash_register_id' => $this->cash_register_id,
            'cashier_id' => $this->cashier_id,
            'opened_by' => $this->opened_by,
            'closed_by' => $this->closed_by,
            'status' => $this->status,
            'opening_base_amount' => $this->opening_base_amount,
            'opening_local_amount' => $this->opening_local_amount,
            'expected_base_amount' => $this->expected_base_amount,
            'expected_local_amount' => $this->expected_local_amount,
            'expected_cash_usd' => $this->expected_cash_usd,
            'expected_cash_ves' => $this->expected_cash_ves,
            'counted_base_amount' => $this->counted_base_amount,
            'counted_local_amount' => $this->counted_local_amount,
            'counted_cash_usd' => $this->counted_cash_usd,
            'counted_cash_ves' => $this->counted_cash_ves,
            'difference_base_amount' => $this->difference_base_amount,
            'difference_local_amount' => $this->difference_local_amount,
            'difference_cash_usd' => $this->difference_cash_usd,
            'difference_cash_ves' => $this->difference_cash_ves,
            'opened_at' => $this->opened_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'notes' => $this->notes,
            'closing_notes' => $this->closing_notes,
            'counting_mode' => $this->counting_mode,
            'review_status' => $this->review_status,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'review_notes' => $this->review_notes,
            'branch' => $this->whenLoaded('branch'),
            'cash_register' => CashRegisterResource::make($this->whenLoaded('cashRegister')),
            'cashier' => $this->whenLoaded('cashier', fn () => [
                'id' => $this->cashier?->id,
                'name' => $this->cashier?->name,
                'email' => $this->cashier?->email,
            ]),
            'closer' => $this->whenLoaded('closer', fn () => [
                'id' => $this->closer?->id,
                'name' => $this->closer?->name,
                'email' => $this->closer?->email,
            ]),
            'reviewer' => $this->whenLoaded('reviewer', fn () => [
                'id' => $this->reviewer?->id,
                'name' => $this->reviewer?->name,
                'email' => $this->reviewer?->email,
            ]),
            'movements' => CashRegisterMovementResource::collection($this->whenLoaded('movements')),
            'counts' => $this->whenLoaded('counts', fn () => $this->counts->map(fn ($count) => [
                'currency' => $count->currency,
                'denomination' => $count->denomination,
                'quantity' => $count->quantity,
                'total_amount' => $count->total_amount,
            ])->values()),
        ];
    }
}
