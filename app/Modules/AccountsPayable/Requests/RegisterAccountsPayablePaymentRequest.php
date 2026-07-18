<?php

namespace App\Modules\AccountsPayable\Requests;

use App\Modules\Purchases\Models\PurchaseOrder;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterAccountsPayablePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'payment_currency' => ['required', Rule::in([PurchaseOrder::CURRENCY_USD, PurchaseOrder::CURRENCY_VES])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'cash_register_session_id' => ['nullable', 'integer', Rule::exists('cash_register_sessions', 'id')->where('tenant_id', $tenantId)],
            'exchange_rate_type_id' => ['nullable', Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'method' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paid_at' => ['nullable', 'date'],
        ];
    }
}
