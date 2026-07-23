<?php

namespace App\Modules\AccessControl\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantUserRolesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'roles' => ['required', 'array'],
            'roles.*' => ['required', 'string', 'max:150'],
        ];
    }
}
