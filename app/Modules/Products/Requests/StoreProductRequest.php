<?php

namespace App\Modules\Products\Requests;

use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'sku')->where('tenant_id', $tenantId),
            ],
            'tracking_type' => [
                'sometimes',
                'string',
                Rule::in([Product::TRACKING_QUANTITY, Product::TRACKING_SERIALIZED]),
            ],
            'base_price' => ['nullable', 'numeric', 'gte:0'],
            'sale_currency' => [
                'sometimes',
                'string',
                'size:3',
                Rule::in([Product::CURRENCY_USD, Product::CURRENCY_VES]),
            ],
            'sale_exchange_rate_type_id' => [
                'nullable',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId),
            ],
            'warranty_policy_id' => [
                'nullable',
                'integer',
                Rule::exists('warranty_policies', 'id')->where('tenant_id', $tenantId),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del producto es obligatorio.',
            'name.max' => 'El nombre del producto no puede superar 255 caracteres.',
            'sku.required' => 'El SKU del producto es obligatorio.',
            'sku.unique' => 'Ya existe un producto con este SKU en la empresa actual.',
            'sku.max' => 'El SKU no puede superar 255 caracteres.',
            'tracking_type.in' => 'El tipo de control debe ser por cantidad o serializado/IMEI.',
            'base_price.numeric' => 'El precio base debe ser numérico.',
            'base_price.gte' => 'El precio base no puede ser negativo.',
            'sale_currency.in' => 'La moneda de venta debe ser USD o VES.',
            'sale_currency.size' => 'La moneda de venta debe tener 3 caracteres.',
            'sale_exchange_rate_type_id.exists' => 'El tipo de tasa seleccionado no pertenece a la empresa actual.',
            'warranty_policy_id.exists' => 'La política de garantía seleccionada no pertenece a la empresa actual.',
            'is_active.boolean' => 'El estado activo debe ser verdadero o falso.',
        ];
    }
}
