<?php

namespace App\Modules\POS\Requests;

use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosCheckoutRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'customer_name' => ['nullable', 'string', 'max:255'],
            'cash_register_session_id' => [
                'required',
                'integer',
                Rule::exists('cash_register_sessions', 'id')->where('tenant_id', $tenantId),
            ],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.price_list_id' => [
                'nullable',
                'integer',
                Rule::exists('price_lists', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.product_unit_ids' => ['sometimes', 'array'],
            'items.*.product_unit_ids.*' => ['integer', Rule::exists('product_units', 'id')->where('tenant_id', $tenantId)],
            'items.*.discount_type' => ['nullable', 'string', Rule::in(['percent', 'fixed'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_reason' => ['nullable', 'string', 'max:255'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => [
                'nullable',
                'integer',
                Rule::exists('payment_methods', 'id')->where('tenant_id', $tenantId),
            ],
            'payments.*.method' => [
                'required',
                'string',
                Rule::in([
                    PosPayment::METHOD_CASH,
                    PosPayment::METHOD_CARD,
                    PosPayment::METHOD_MOBILE_PAYMENT,
                    PosPayment::METHOD_TRANSFER,
                    PosPayment::METHOD_ZELLE,
                    PosPayment::METHOD_EXTERNAL_FINANCING,
                    PosPayment::METHOD_OTHER,
                ]),
            ],
            'payments.*.currency' => [
                'required',
                'string',
                'size:3',
                Rule::in([Product::CURRENCY_USD, Product::CURRENCY_VES]),
            ],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.exchange_rate_type_id' => [
                'nullable',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId),
            ],
            'payments.*.status' => [
                'sometimes',
                'string',
                Rule::in([PosPayment::STATUS_PENDING, PosPayment::STATUS_CAPTURED, PosPayment::STATUS_FAILED]),
            ],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
            'payments.*.external_provider' => ['nullable', 'string', 'max:255'],
            'payments.*.metadata' => ['nullable', 'array'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
