<?php

namespace App\Modules\Fiscal\Resources;

use App\Modules\Branches\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiscalBranchIdentityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Branch $branch */
        $branch = $this->resource;

        return [
            'id' => $branch->id,
            'tenant_id' => $branch->tenant_id,
            'name' => $branch->name,
            'code' => $branch->code,
            'status' => $branch->status,
            'fiscal_address' => $branch->fiscal_address,
            'city' => $branch->fiscal_city,
            'state' => $branch->fiscal_state,
            'phone' => $branch->fiscal_phone,
            'email' => $branch->fiscal_email,
            'tax_condition' => $branch->tax_condition,
            'created_at' => $branch->created_at?->toISOString(),
            'updated_at' => $branch->updated_at?->toISOString(),
        ];
    }
}
