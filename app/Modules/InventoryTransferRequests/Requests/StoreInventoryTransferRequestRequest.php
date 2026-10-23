<?php

namespace App\Modules\InventoryTransferRequests\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryTransferRequestRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'flow_type' => ['sometimes', 'string', Rule::in(['stock_request', 'shipment_offer'])],
            'destination_tenant_slug' => ['nullable', 'string', 'max:255', 'required_without:destination_user_email'],
            'destination_user_email' => ['nullable', 'email', 'max:255', 'required_without:destination_tenant_slug'],
            'from_warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', app(TenantManager::class)->require()->id)],
            'reason' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->whereIn('tenant_id', $tenantIds)],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.product_unit_ids' => ['nullable', 'array'],
            'items.*.product_unit_ids.*' => ['integer'],
            'items.*.serial_units' => ['nullable', 'array'],
            'items.*.serial_units.*.serial_type' => ['required_with:items.*.serial_units', 'string', 'in:imei,serial'],
            'items.*.serial_units.*.serial_number' => ['required_with:items.*.serial_units', 'string', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $items = $this->input('items', []);
            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                if (empty($item['product_id'])) {
                    continue;
                }

                $hasVariants = \App\Modules\Products\Models\ProductVariant::query()
                    ->where('product_id', $item['product_id'])
                    ->where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNotNull('color')->orWhereNotNull('sku_variant');
                    })
                    ->exists();

                if ($hasVariants && empty($item['product_variant_id'])) {
                    $validator->errors()->add(
                        "items.{$index}.product_variant_id",
                        'El producto seleccionado tiene variantes (colores/modelos). Debe seleccionar una variante obligatoria.'
                    );
                }
            }
        });
    }
}
