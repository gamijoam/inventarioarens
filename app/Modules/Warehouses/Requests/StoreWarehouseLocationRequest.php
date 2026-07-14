<?php

namespace App\Modules\Warehouses\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $warehouseId = $this->route('warehouse')?->id ?? $this->input('warehouse_id');

        return [
            'warehouse_id' => ['nullable', 'integer'],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('warehouse_locations', 'id')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('warehouse_locations', 'code')
                    ->where('tenant_id', $tenantId)
                    ->where('warehouse_id', $warehouseId),
            ],
            'aisle' => ['nullable', 'string', 'max:20'],
            'rack' => ['nullable', 'string', 'max:20'],
            'bin' => ['nullable', 'string', 'max:20'],
            'level' => ['nullable', 'string', 'max:20'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
