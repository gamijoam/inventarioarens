<?php

namespace App\Modules\CashRegister\Requests;

use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CloseCashRegisterSessionRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'counted_currency' => [
                'sometimes',
                'string',
                'size:3',
                Rule::in([Product::CURRENCY_USD, Product::CURRENCY_VES]),
            ],
            'counted_amount' => ['required', 'numeric', 'gte:0'],
            'exchange_rate_type_id' => [
                'nullable',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId),
            ],
            'closing_notes' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
