<?php

namespace App\Modules\Purchases\Requests;

use App\Modules\Purchases\Models\PurchaseOrder;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'document_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('purchase_orders', 'document_number')->where(fn ($query) => $query->whereIn('tenant_id', $tenantIds)),
            ],
            'issued_at' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'purchase_currency' => ['required', 'string', Rule::in([
                PurchaseOrder::CURRENCY_USD,
                PurchaseOrder::CURRENCY_VES,
            ])],
            'exchange_rate_type_id' => ['nullable', Rule::exists('exchange_rate_types', 'id')->whereIn('tenant_id', $tenantIds)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->whereIn('tenant_id', $tenantIds)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'gt:0'],
            'items.*.serial_units' => ['sometimes', 'array'],
            'items.*.serial_units.*.serial_type' => ['required_with:items.*.serial_units', 'string', Rule::in(['imei', 'serial'])],
            'items.*.serial_units.*.serial_number' => ['required_with:items.*.serial_units', 'string', 'max:255'],
        ];
    }
}
