<?php

namespace App\Modules\Branches\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'code' => $this->code,
            'status' => $this->status,
            'fiscal_address' => $this->fiscal_address,
            'fiscal_city' => $this->fiscal_city,
            'fiscal_state' => $this->fiscal_state,
            'fiscal_phone' => $this->fiscal_phone,
            'fiscal_email' => $this->fiscal_email,
            'tax_condition' => $this->tax_condition,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
