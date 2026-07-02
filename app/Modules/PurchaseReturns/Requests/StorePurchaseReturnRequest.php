<?php

namespace App\Modules\PurchaseReturns\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReturnRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'purchase_order_id' => ['required', Rule::exists('purchase_orders', 'id')->where('tenant_id', $tenantId)],
            'reason' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id' => ['required', Rule::exists('purchase_items', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
            'items.*.product_unit_ids' => ['sometimes', 'array'],
            'items.*.product_unit_ids.*' => ['integer', Rule::exists('product_units', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
