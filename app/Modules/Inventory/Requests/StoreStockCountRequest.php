<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\StockCount;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('stock_counts', 'code')
                    ->where('tenant_id', $tenantId)
                    ->where('warehouse_id', $this->input('warehouse_id')),
            ],
            'name' => ['required', 'string', 'max:150'],
            'count_type' => ['sometimes', Rule::in(StockCount::ALLOWED_TYPES)],
            'scheduled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
