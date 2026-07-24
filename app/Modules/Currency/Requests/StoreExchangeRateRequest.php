<?php

namespace App\Modules\Currency\Requests;

use App\Modules\Currency\Models\ExchangeRate;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExchangeRateRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'exchange_rate_type_id' => [
                'required',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'base_currency' => ['sometimes', 'string', 'size:3', Rule::in([ExchangeRate::BASE_USD])],
            'quote_currency' => ['sometimes', 'string', 'size:3', Rule::in([ExchangeRate::QUOTE_VES])],
            'rate' => ['required', 'numeric', 'gt:0'],
            'effective_at' => ['required', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'source' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
