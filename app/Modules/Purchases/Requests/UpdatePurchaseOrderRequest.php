<?php

namespace App\Modules\Purchases\Requests;

use App\Modules\Purchases\Models\PurchaseOrder;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var PurchaseOrder|null $purchaseOrder */
        $purchaseOrder = $this->route('purchaseOrder');

        if (! $purchaseOrder instanceof PurchaseOrder) {
            return [];
        }

        if ($purchaseOrder->status === PurchaseOrder::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'status' => 'No se pueden editar compras canceladas.',
            ]);
        }

        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        $commonRules = [
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'document_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('purchase_orders', 'document_number')
                    ->where(fn ($query) => $query->whereIn('tenant_id', $tenantIds))
                    ->ignore($purchaseOrder->id),
            ],
            'issued_at' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issued_at'],
        ];

        // Si la compra ya fue recibida o parcialmente recibida, solo se permiten editar metadatos comerciales/fiscales
        if (in_array($purchaseOrder->status, [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED], true)) {
            return [
                ...$commonRules,
                'items' => ['prohibited'],
                'purchase_currency' => ['prohibited'],
                'exchange_rate_type_id' => ['prohibited'],
            ];
        }

        // Si está en borrador, permite edición completa
        return [
            ...$commonRules,
            'purchase_currency' => ['required', 'string', Rule::in([
                PurchaseOrder::CURRENCY_USD,
                PurchaseOrder::CURRENCY_VES,
            ])],
            'exchange_rate_type_id' => ['nullable', Rule::exists('exchange_rate_types', 'id')->whereIn('tenant_id', $tenantIds)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->whereIn('tenant_id', $tenantIds)],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->whereIn('tenant_id', $tenantIds)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'gt:0'],
            'items.*.new_sale_price' => ['nullable', 'numeric', 'gt:0'],
            'items.*.serial_units' => ['sometimes', 'array'],
            'items.*.serial_units.*.serial_type' => ['required_with:items.*.serial_units', 'string', Rule::in(['imei', 'serial'])],
            'items.*.serial_units.*.serial_number' => ['required_with:items.*.serial_units', 'string', 'max:255'],
        ];
    }
}
