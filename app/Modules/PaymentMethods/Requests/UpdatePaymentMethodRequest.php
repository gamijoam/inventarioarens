<?php

namespace App\Modules\PaymentMethods\Requests;

use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];
        $paymentMethod = $this->route('paymentMethod');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('payment_methods', 'code')
                    ->where(fn ($query) => $query->whereIn('tenant_id', $tenantIds))
                    ->ignore($paymentMethod?->id),
            ],
            'method' => ['sometimes', 'required', 'string', Rule::in(StorePaymentMethodRequest::methods())],
            'currency_mode' => ['sometimes', 'required', 'string', Rule::in([
                PaymentMethod::CURRENCY_USD,
                PaymentMethod::CURRENCY_VES,
                PaymentMethod::CURRENCY_FLEXIBLE,
            ])],
            'requires_reference' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('payment_methods.update') === true;
    }
}
