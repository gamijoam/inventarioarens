<?php

namespace App\Modules\Suppliers\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierCreditRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'payment_currency' => ['required', 'string', 'in:USD,VES'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate_type_id' => ['nullable', 'integer', Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'method' => ['nullable', 'string', 'max:100'],
            'cash_register_session_id' => ['nullable', 'integer', Rule::exists('cash_register_sessions', 'id')->where('tenant_id', $tenantId)],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
