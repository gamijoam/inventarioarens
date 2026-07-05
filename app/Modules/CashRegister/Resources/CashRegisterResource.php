<?php

namespace App\Modules\CashRegister\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashRegisterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'code' => $this->code,
            'status' => $this->status,
            'notes' => $this->notes,
            'branch' => $this->whenLoaded('branch'),
            'open_session' => CashRegisterSessionResource::make($this->whenLoaded('openSession')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
