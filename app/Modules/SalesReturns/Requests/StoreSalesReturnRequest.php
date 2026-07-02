<?php

namespace App\Modules\SalesReturns\Requests;

use App\Modules\SalesReturns\Models\SalesReturnItem;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesReturnRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'sale_id' => ['required', Rule::exists('sales', 'id')->where('tenant_id', $tenantId)],
            'reason' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', Rule::exists('sale_items', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.condition' => ['sometimes', 'string', Rule::in([
                SalesReturnItem::CONDITION_SELLABLE,
                SalesReturnItem::CONDITION_DAMAGED,
            ])],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
            'items.*.product_unit_ids' => ['sometimes', 'array'],
            'items.*.product_unit_ids.*' => ['integer', Rule::exists('product_units', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
