<?php

namespace App\Modules\Warranties\Requests;

use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarrantyPolicyRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $policy = $this->route('warrantyPolicy');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                Rule::unique('warranty_policies', 'name')
                    ->where('tenant_id', $tenantId)
                    ->ignore($policy?->id),
            ],
            'duration_days' => ['sometimes', 'required', 'integer', 'gte:0', 'lte:3650'],
            'coverage_type' => ['sometimes', 'required', Rule::in([
                WarrantyPolicy::COVERAGE_STORE,
                WarrantyPolicy::COVERAGE_MANUFACTURER,
                WarrantyPolicy::COVERAGE_NONE,
            ])],
            'conditions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
