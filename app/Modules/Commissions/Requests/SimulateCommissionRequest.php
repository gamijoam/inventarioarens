<?php

namespace App\Modules\Commissions\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimulateCommissionRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'amount' => ['required', 'numeric', 'gte:0'],
            'currency' => ['required', Rule::in(['USD', 'VES'])],
            'percentage' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'exchange_rate_type_id' => [
                Rule::requiredIf($this->input('currency') === 'VES'),
                'nullable',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
