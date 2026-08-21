<?php

namespace App\Modules\Commissions\Requests;

use App\Modules\Commissions\Models\CommissionPlan;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommissionAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('commissions.adjust') === true;
    }

    public function rules(): array
    {
        return [
            'beneficiary_user_id' => [
                'required',
                'integer',
                Rule::exists('tenant_user', 'user_id')->where(fn ($query) => $query
                    ->where('tenant_id', app(TenantManager::class)->require()->id)
                    ->where('status', 'active')),
            ],
            'beneficiary_role' => ['nullable', Rule::in([CommissionPlan::ROLE_SELLER, CommissionPlan::ROLE_CASHIER, CommissionPlan::ROLE_TECHNICIAN])],
            'amount_base' => ['required', 'numeric', 'not_in:0', 'between:-999999999999.9999,999999999999.9999'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
