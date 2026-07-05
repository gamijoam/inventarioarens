<?php

namespace App\Modules\CashRegister\Requests;

use App\Modules\CashRegister\Models\CashRegister;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCashRegisterRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $cashRegister = $this->route('cashRegister');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:40',
                Rule::unique('cash_registers', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($cashRegister?->id),
            ],
            'status' => [
                'sometimes',
                'required',
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
