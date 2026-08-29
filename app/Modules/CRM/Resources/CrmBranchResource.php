<?php

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrmBranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->id,
            'code' => $this->code,
            'branch_code' => $this->code,
            'name' => $this->name,
            'branch_name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'location' => null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
