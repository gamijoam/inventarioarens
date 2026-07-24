<?php

namespace App\Modules\FinancialAdjustments\Requests;

use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAdjustmentRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'account_type' => ['required', Rule::in([FinancialAdjustment::ACCOUNT_RECEIVABLE, FinancialAdjustment::ACCOUNT_PAYABLE])],
            'account_id' => ['required', 'integer'],
            'currency' => ['required', Rule::in([Product::CURRENCY_USD, Product::CURRENCY_VES])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate_type_id' => ['nullable', Rule::exists('exchange_rate_types', 'id')->whereIn('tenant_id', $tenantIds)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'applied_at' => ['nullable', 'date'],
        ];
    }
}
