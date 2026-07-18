<?php

namespace App\Modules\AccountsPayable\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecuteAccountsPayablePaymentRequestRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'cash_register_session_id' => ['nullable', 'integer', Rule::exists('cash_register_sessions', 'id')->where('tenant_id', $tenantId)],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
