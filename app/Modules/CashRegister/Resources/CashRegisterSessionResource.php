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
            'branch_id' => $this->branch_id,
            'cashier_id' => $this->cashier_id,
            'opened_by' => $this->opened_by,
            'closed_by' => $this->closed_by,
            'status' => $this->status,
            'opening_base_amount' => $this->opening_base_amount,
            'opening_local_amount' => $this->opening_local_amount,
            'expected_base_amount' => $this->expected_base_amount,
            'expected_local_amount' => $this->expected_local_amount,
            'counted_base_amount' => $this->counted_base_amount,
            'counted_local_amount' => $this->counted_local_amount,
            'difference_base_amount' => $this->difference_base_amount,
            'difference_local_amount' => $this->difference_local_amount,
            'opened_at' => $this->opened_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'notes' => $this->notes,
            'closing_notes' => $this->closing_notes,
            'branch' => $this->whenLoaded('branch'),
            'movements' => CashRegisterMovementResource::collection($this->whenLoaded('movements')),
        ];
    }
}
