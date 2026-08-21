<?php

namespace App\Modules\Workshop\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTechnicianRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'technician_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
            ],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
