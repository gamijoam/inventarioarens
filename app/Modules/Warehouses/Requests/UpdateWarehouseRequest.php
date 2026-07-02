<?php

namespace App\Modules\Warehouses\Requests;

use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $warehouse = $this->route('warehouse');

        return [
            'branch_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('branches', 'id')->where('tenant_id', $tenantId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('warehouses', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($warehouse?->id),
            ],
            'status' => ['sometimes', 'required', 'string', Rule::in([Warehouse::STATUS_ACTIVE, Warehouse::STATUS_INACTIVE])],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
