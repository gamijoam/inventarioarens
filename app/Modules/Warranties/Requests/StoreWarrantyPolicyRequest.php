<?php

namespace App\Modules\Warranties\Requests;

use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarrantyPolicyRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('warranty_policies', 'name')->where('tenant_id', $tenantId),
            ],
            'duration_days' => ['required', 'integer', 'gte:0', 'lte:3650'],
            'coverage_type' => ['required', Rule::in([
                WarrantyPolicy::COVERAGE_STORE,
                WarrantyPolicy::COVERAGE_MANUFACTURER,
                WarrantyPolicy::COVERAGE_NONE,
            ])],
            'conditions' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
