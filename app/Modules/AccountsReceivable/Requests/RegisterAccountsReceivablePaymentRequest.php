<?php

namespace App\Modules\AccountsReceivable\Requests;

use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterAccountsReceivablePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'payment_currency' => ['required', Rule::in([Product::CURRENCY_USD, Product::CURRENCY_VES])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate_type_id' => ['nullable', Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'method' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paid_at' => ['nullable', 'date'],
        ];
    }
}
