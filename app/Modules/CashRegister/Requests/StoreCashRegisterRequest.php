<?php

namespace App\Modules\CashRegister\Requests;

use App\Modules\CashRegister\Models\CashRegister;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashRegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'max:40',
                Rule::unique('cash_registers', 'code')->where('tenant_id', $tenantId),
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in([CashRegister::STATUS_ACTIVE, CashRegister::STATUS_INACTIVE]),
            ],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
