<?php

namespace App\Modules\Products\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceListRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('price_lists', 'code')->where('tenant_id', $tenantId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'payment_method_ids' => ['sometimes', 'array'],
            'payment_method_ids.*' => [
                'integer',
                Rule::exists('payment_methods', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('products.update') === true;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la lista de precio es obligatorio.',
            'code.required' => 'El código de la lista de precio es obligatorio.',
            'code.unique' => 'Ya existe una lista de precio con este código en la empresa actual.',
        ];
    }
}
