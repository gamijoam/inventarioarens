<?php

namespace App\Modules\Products\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceListRequest extends FormRequest
{
    public function rules(): array
    {
        // Scope estricto por tenant: las listas de precio son locales.
        // Usamos solo el tenant actual para evitar choques con la FK
        // compuesta del pivote price_list_payment_method
        // (tenant_id, price_list_id, payment_method_id).
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('price_lists', 'code')->where(fn ($query) => $query->whereIn('tenant_id', $tenantIds))],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'payment_method_ids' => ['sometimes', 'array'],
            'payment_method_ids.*' => [
                'integer',
                Rule::exists('payment_methods', 'id')->whereIn('tenant_id', $tenantIds),
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
            'code.required' => 'El codigo de la lista de precio es obligatorio.',
            'code.unique' => 'Ya existe una lista de precio con este codigo en la empresa actual.',
            'payment_method_ids.*.exists' => 'Uno o mas metodos de pago seleccionados no existen en la empresa actual.',
        ];
    }
}
