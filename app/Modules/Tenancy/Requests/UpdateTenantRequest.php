<?php

namespace App\Modules\Tenancy\Requests;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = $this->route('tenant');
        $tenantId = $tenant instanceof Tenant ? $tenant->id : (int) $tenant;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('tenants', 'slug')->ignore($tenantId)],
            'domain' => ['sometimes', 'nullable', 'string', 'max:150', Rule::unique('tenants', 'domain')->ignore($tenantId)],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive'],
            'plan' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
