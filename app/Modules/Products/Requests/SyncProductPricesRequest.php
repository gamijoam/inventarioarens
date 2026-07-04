<?php

namespace App\Modules\Products\Requests;

use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncProductPricesRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'prices' => ['required', 'array'],
            'prices.*.price_list_id' => [
                'required',
                'integer',
                Rule::exists('price_lists', 'id')->where('tenant_id', $tenantId),
            ],
            'prices.*.price' => ['required', 'numeric', 'gte:0'],
            'prices.*.currency' => ['required', 'string', 'size:3', Rule::in([Product::CURRENCY_USD, Product::CURRENCY_VES])],
            'prices.*.exchange_rate_type_id' => [
                'nullable',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId),
            ],
            'prices.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product && $this->user()?->can('update', $product) === true;
    }

    public function messages(): array
    {
        return [
            'prices.required' => 'Debes enviar al menos una lista de precio.',
            'prices.*.price_list_id.exists' => 'Una lista de precio no pertenece a la empresa actual.',
            'prices.*.price.required' => 'El precio es obligatorio.',
            'prices.*.price.numeric' => 'El precio debe ser numérico.',
            'prices.*.price.gte' => 'El precio no puede ser negativo.',
            'prices.*.currency.in' => 'La moneda del precio debe ser USD o VES.',
            'prices.*.exchange_rate_type_id.exists' => 'El tipo de tasa seleccionado no pertenece a la empresa actual.',
        ];
    }
}
