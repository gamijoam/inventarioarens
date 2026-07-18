<?php

namespace App\Modules\CashRegister\Requests;

use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenCashRegisterSessionRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where('tenant_id', $tenantId),
            ],
            'cash_register_id' => [
                'nullable',
                'integer',
                Rule::exists('cash_registers', 'id')->where('tenant_id', $tenantId),
            ],
            'cashier_id' => [
                'sometimes',
                'integer',
                Rule::exists('users', 'id'),
            ],
            'opening_currency' => [
                'sometimes',
                'string',
                'size:3',
                Rule::in([Product::CURRENCY_USD, Product::CURRENCY_VES]),
            ],
            'opening_amount' => ['sometimes', 'numeric', 'gte:0'],
            'opening_base_amount' => ['sometimes', 'numeric', 'gte:0'],
            'opening_local_amount' => ['sometimes', 'numeric', 'gte:0'],
            'exchange_rate_type_id' => [
                'nullable',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId),
            ],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
