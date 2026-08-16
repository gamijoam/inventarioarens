<?php

namespace App\Modules\POS\Requests;

use App\Modules\Promotions\Models\Promotion;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosHoldRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'customer_name' => ['nullable', 'string', 'max:255'],
            'promotion_id' => [
                'nullable',
                'integer',
                Rule::exists('promotions', 'id')->where('tenant_id', $tenantId),
            ],
            'promotion_code' => ['nullable', 'string', 'max:80'],
            'invoice_promotion_id' => [
                'nullable',
                'integer',
                Rule::exists('promotions', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('scope', Promotion::SCOPE_INVOICE)),
            ],
            'invoice_promotion_code' => ['nullable', 'string', 'max:80'],
            'combo_applications' => ['sometimes', 'array'],
            'combo_applications.*.promotion_id' => [
                'required',
                'integer',
                Rule::exists('promotions', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('scope', Promotion::SCOPE_COMBO)),
            ],
            'combo_applications.*.instance_uuid' => ['required', 'string', 'max:100', 'distinct'],
            'combo_applications.*.sets' => ['required', 'integer', 'min:1', 'max:99'],
            'product_offer_applications' => ['sometimes', 'array'],
            'product_offer_applications.*.promotion_id' => [
                'required',
                'integer',
                Rule::exists('promotions', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('scope', Promotion::SCOPE_PRODUCT_OFFER)),
            ],
            'product_offer_applications.*.item_index' => ['required', 'integer', 'min:0', 'distinct'],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'items.*.price_list_id' => [
                'nullable',
                'integer',
                Rule::exists('price_lists', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'items.*.price_source' => ['nullable', 'string', Rule::in(['base', 'price_list', 'list'])],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.combo_instance_uuid' => ['nullable', 'string', 'max:100'],
            'items.*.product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'items.*.product_unit_ids' => ['sometimes', 'array'],
            'items.*.product_unit_ids.*' => ['integer', Rule::exists('product_units', 'id')->where('tenant_id', $tenantId)],
            'items.*.discount_type' => ['nullable', 'string', Rule::in(['percent', 'fixed'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ($this->input('product_offer_applications', []) as $index => $application) {
                $itemIndex = $application['item_index'] ?? null;
                if (! is_numeric($itemIndex) || (int) $itemIndex < 0) {
                    continue;
                }

                if (! array_key_exists((int) $itemIndex, $this->input('items', []))) {
                    $validator->errors()->add(
                        "product_offer_applications.{$index}.item_index",
                        'El item_index debe corresponder a una linea de la orden.',
                    );
                }
            }
        });
    }
}
