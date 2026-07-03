<?php

namespace App\Modules\Warranties\Requests;

use App\Modules\Warranties\Models\WarrantyClaim;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveWarrantyClaimRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'resolution_type' => [
                'required',
                'string',
                Rule::in([
                    WarrantyClaim::RESOLUTION_REPLACEMENT,
                    WarrantyClaim::RESOLUTION_REJECTED,
                ]),
            ],
            'replacement_product_unit_id' => [
                'nullable',
                'integer',
                Rule::exists('product_units', 'id')->where('tenant_id', $tenantId),
            ],
            'replacement_warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'resolution_notes' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
