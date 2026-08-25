<?php

namespace App\Modules\AccessControl\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceUserScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'warehouse_ids' => ['sometimes', 'array'],
            'warehouse_ids.*' => ['integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'customer_group_ids' => ['sometimes', 'array'],
            'customer_group_ids.*' => ['integer', Rule::exists('customer_groups', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
