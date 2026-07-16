<?php

namespace App\Modules\Tenancy\Requests;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantGroupRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id;

        return [
            'group.name' => ['required', 'string', 'max:255'],
            'group.slug' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tenants', 'slug')->whereNull('parent_id'),
            ],
            'group.plan' => ['nullable', 'string', 'max:50'],
            'group.domain' => ['nullable', 'string', 'max:255'],

            'tenant.name' => ['required', 'string', 'max:255'],
            'tenant.slug' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tenants', 'slug'),
            ],
            'tenant.domain' => ['nullable', 'string', 'max:255'],
            'tenant.plan' => ['nullable', 'string', 'max:50'],

            'tenant.branch.name' => ['nullable', 'string', 'max:255'],
            'tenant.branch.code' => ['nullable', 'string', 'max:50'],

            'tenant.warehouse.name' => ['nullable', 'string', 'max:255'],
            'tenant.warehouse.code' => ['nullable', 'string', 'max:50'],

            'tenant.exchange_rate_type.code' => ['nullable', 'string', 'max:50'],
            'tenant.exchange_rate_type.name' => ['nullable', 'string', 'max:255'],

            'admin.name' => ['required', 'string', 'max:255'],
            'admin.email' => ['required', 'email', 'max:255'],
            'admin.password' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}