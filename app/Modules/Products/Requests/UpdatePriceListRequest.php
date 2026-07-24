<?php

namespace App\Modules\Products\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePriceListRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];
        $priceList = $this->route('priceList');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('price_lists', 'code')
                    ->where(fn ($query) => $query->whereIn('tenant_id', $tenantIds))
                    ->ignore($priceList?->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'payment_method_ids' => ['sometimes', 'array'],
            'payment_method_ids.*' => [
                'integer',
                Rule::exists('payment_methods', 'id')->whereIn('tenant_id', $tenantIds),
            ],
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('products.update') === true;
    }
}
