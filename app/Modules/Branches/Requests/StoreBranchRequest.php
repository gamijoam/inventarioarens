<?php

namespace App\Modules\Branches\Requests;

use App\Modules\Branches\Models\Branch;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('branches', 'code')->where('tenant_id', $tenantId),
            ],
            'status' => ['sometimes', 'string', Rule::in([Branch::STATUS_ACTIVE, Branch::STATUS_INACTIVE])],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
