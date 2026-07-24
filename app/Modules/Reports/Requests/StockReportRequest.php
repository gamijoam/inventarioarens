<?php

namespace App\Modules\Reports\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('reports.view') ?? false)
            || ($this->user()?->can('reports.inventory.view') ?? false);
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'warehouse_id' => [
                'sometimes',
                'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'product_id' => [
                'sometimes',
                'integer',
                Rule::exists('products', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'threshold' => ['sometimes', 'numeric', 'gte:0'],
        ];
    }
}
