<?php

namespace App\Modules\POS\Requests;

use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddPosOrderPaymentsRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => [
                'nullable',
                'integer',
                Rule::exists('payment_methods', 'id')->whereIn('tenant_id', $tenantIds),
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
                Rule::exists('exchange_rate_types', 'id')->whereIn('tenant_id', $tenantIds),
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
