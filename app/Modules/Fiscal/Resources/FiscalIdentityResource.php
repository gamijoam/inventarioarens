<?php

namespace App\Modules\Fiscal\Resources;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiscalIdentityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->resource['tenant'];
        $company = $this->resource['company'];

        return [
            'tenant' => [
                'id' => $tenant->id,
                'legal_name' => $company['razon_social'] ?? null,
                'tax_id' => $company['rif'] ?? null,
                'fiscal_address' => $company['domicilio_fiscal'] ?? null,
                'city' => $company['ciudad'] ?? null,
                'state' => $company['estado'] ?? null,
                'phone' => $company['telefono'] ?? null,
                'email' => $company['correo'] ?? null,
                'tax_condition' => $company['tax_condition'] ?? null,
            ],
            'branches' => FiscalBranchIdentityResource::collection($this->resource['branches']),
        ];
    }
}
