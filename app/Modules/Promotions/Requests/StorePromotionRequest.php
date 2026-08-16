<?php

namespace App\Modules\Promotions\Requests;

use App\Modules\Promotions\Models\Promotion;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePromotionRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $benefitType = $this->input('benefit_type');
        $scope = $this->route('promotion_scope');
        $allowedBenefitTypes = match ($scope) {
            Promotion::SCOPE_INVOICE => [
                Promotion::BENEFIT_PERCENT_DISCOUNT,
                Promotion::BENEFIT_FIXED_DISCOUNT,
            ],
            Promotion::SCOPE_COMBO => [
                Promotion::BENEFIT_FIXED_BUNDLE_PRICE,
                Promotion::BENEFIT_BUY_X_GET_Y,
            ],
            Promotion::SCOPE_PRODUCT_OFFER => [
                Promotion::BENEFIT_FIXED_ITEM_PRICE,
                Promotion::BENEFIT_FREE_ITEM,
            ],
            default => [
                Promotion::BENEFIT_PERCENT_DISCOUNT,
                Promotion::BENEFIT_FIXED_DISCOUNT,
                Promotion::BENEFIT_FIXED_ITEM_PRICE,
                Promotion::BENEFIT_FIXED_BUNDLE_PRICE,
                Promotion::BENEFIT_FREE_ITEM,
                Promotion::BENEFIT_BUY_X_GET_Y,
            ],
        };
        $requiresItems = $scope === Promotion::SCOPE_INVOICE
            ? false
            : ! Promotion::isInvoiceDiscountType($benefitType);

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:80',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('promotions', 'code')->where('tenant_id', $tenantId),
            ],
            'benefit_type' => ['required', 'string', Rule::in($allowedBenefitTypes)],
            'price_currency' => ['sometimes', 'string', 'size:3', Rule::in(['USD'])],
            'payment_currency' => ['sometimes', 'string', 'size:3', Rule::in([
                Promotion::PAYMENT_CURRENCY_ANY,
                Promotion::PAYMENT_CURRENCY_VES,
            ])],
            'allows_combos' => ['sometimes', 'boolean'],
            'price_usd' => ['required_if:benefit_type,fixed_item_price,fixed_bundle_price', 'nullable', 'numeric', 'gte:0'],
            'discount_percent' => ['required_if:benefit_type,percent_discount', 'nullable', 'numeric', 'gt:0', 'lte:100'],
            'discount_amount_usd' => ['required_if:benefit_type,fixed_discount', 'nullable', 'numeric', 'gt:0'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'items' => array_merge(
                [$requiresItems ? 'required' : 'sometimes', 'array'],
                $requiresItems ? ['min:1'] : [],
                $benefitType === Promotion::BENEFIT_FIXED_BUNDLE_PRICE ? ['min:2'] : [],
            ),
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.item_role' => ['nullable', 'string', Rule::in(['eligible', 'trigger', 'reward'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('benefit_type') !== Promotion::BENEFIT_BUY_X_GET_Y) {
                return;
            }

            $roles = collect($this->input('items', []))->pluck('item_role');
            if (! $roles->contains('trigger') || ! $roles->contains('reward')) {
                $validator->errors()->add('items', 'La promocion debe tener componentes trigger y reward.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
        }
    }
}
