<?php

namespace App\Modules\Sync\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterSyncNodeRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['nullable', 'string', Rule::in(['local', 'cloud', 'branch', 'worker'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
