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
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'payment_currency' => ['required', Rule::in([Product::CURRENCY_USD, Product::CURRENCY_VES])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'cash_register_session_id' => [
                'required',
                'integer',
                Rule::exists('cash_register_sessions', 'id')->where('tenant_id', $tenantId),
            ],
            'exchange_rate_type_id' => ['nullable', Rule::exists('exchange_rate_types', 'id')->whereIn('tenant_id', $tenantIds)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'method' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paid_at' => ['nullable', 'date'],
        ];
    }
}
