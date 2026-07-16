<?php

namespace App\Modules\Products\Requests;

use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $product = $this->route('product');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'long_description' => ['sometimes', 'nullable', 'string', 'max:50000'],

            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'sku')
                    ->where('tenant_id', $tenantId)
                    ->ignore($product?->id),
            ],
            'barcode' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('products', 'barcode')
                    ->where('tenant_id', $tenantId)
                    ->ignore($product?->id)
                    ->whereNotNull('barcode'),
            ],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:500'],

            'unit_of_measure' => [
                'sometimes',
                'string',
                Rule::in(Product::ALLOWED_UNITS),
            ],
            'track_stock' => ['sometimes', 'boolean'],
            'tracking_type' => [
                'sometimes',
                'required',
                'string',
                Rule::in([Product::TRACKING_QUANTITY, Product::TRACKING_SERIALIZED]),
            ],

            'brand_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('brands', 'id')->where('tenant_id', $tenantId),
            ],

            'base_price' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'profit_margin' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'lte:999.99'],
            'min_stock' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'max_stock' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'reorder_quantity' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'average_cost' => ['prohibited'],

            'sale_currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
                Rule::in([Product::CURRENCY_USD, Product::CURRENCY_VES]),
            ],
            'sale_exchange_rate_type_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId),
            ],
            'warranty_policy_id' => [
                'sometimes',
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
            'sku.required' => 'El SKU del producto es obligatorio.',
            'sku.unique' => 'Ya existe un producto con este SKU en la empresa actual.',
            'barcode.unique' => 'Ya existe un producto con este codigo de barras en la empresa actual.',
            'tracking_type.in' => 'El tipo de control debe ser por cantidad o serializado/IMEI.',
            'unit_of_measure.in' => 'La unidad de medida debe ser unit, kg, lt o m.',
            'base_price.gte' => 'El precio base no puede ser negativo.',
            'min_stock.gte' => 'El stock minimo no puede ser negativo.',
            'max_stock.gte' => 'El stock maximo no puede ser negativo.',
            'average_cost.prohibited' => 'El costo promedio se calcula automaticamente, no se puede asignar manualmente.',
            'brand_id.exists' => 'La marca seleccionada no pertenece a la empresa actual.',
            'sale_currency.in' => 'La moneda de venta debe ser USD o VES.',
            'is_active.boolean' => 'El estado activo debe ser verdadero o falso.',
        ];
    }
}
