<?php

namespace App\Modules\Fiscal\Requests;

use App\Modules\Fiscal\Services\FiscalIdentityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchFiscalIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'fiscal_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'tax_condition' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(FiscalIdentityService::TAX_CONDITIONS),
            ],
        ];
    }
}
