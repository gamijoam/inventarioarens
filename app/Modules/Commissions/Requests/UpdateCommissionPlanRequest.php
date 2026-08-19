<?php

namespace App\Modules\Commissions\Requests;

use App\Modules\Commissions\Models\CommissionPlan;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCommissionPlanRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $plan = $this->route('commissionPlan');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('commission_plans', 'name')->where('tenant_id', $tenantId)->ignore($plan?->id)],
            'beneficiary_role' => ['sometimes', 'required', Rule::in([CommissionPlan::ROLE_SELLER, CommissionPlan::ROLE_CASHIER])],
            'percentage' => ['sometimes', 'required', 'numeric', 'gt:0', 'lte:100'],
            'conversion_policy' => ['sometimes', 'required', Rule::in([CommissionPlan::CONVERSION_SALE_SNAPSHOT, CommissionPlan::CONVERSION_CONFIGURED_RATE])],
            'exchange_rate_type_id' => ['nullable', 'integer', Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId)],
            'credit_policy' => ['sometimes', 'required', Rule::in([CommissionPlan::CREDIT_PROPORTIONAL_COLLECTIONS, CommissionPlan::CREDIT_SALE_CONFIRMATION])],
            'maturation_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'allow_self_stacking' => ['sometimes', 'boolean'],
            'include_combos' => ['sometimes', 'boolean'],
            'include_discounts' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'user_ids' => ['sometimes', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('tenant_user', 'user_id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('status', 'active'))],
        ];
    }
}
