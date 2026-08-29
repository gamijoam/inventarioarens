<?php

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrmAvailabilityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $value = $this->input('include_alternatives');
        if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
            $this->merge(['include_alternatives' => strtolower($value) === 'true']);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:120'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'product_ids' => ['nullable', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'distinct', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_alternatives' => ['sometimes', 'boolean'],
        ];
    }
}
