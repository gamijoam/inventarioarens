<?php

namespace App\Modules\PaymentMethods\Requests;

use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\POS\Models\PosPayment;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('payment_methods', 'code')->where('tenant_id', $tenantId)],
            'method' => ['required', 'string', Rule::in(self::methods())],
            'currency_mode' => ['required', 'string', Rule::in([
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

    public static function methods(): array
    {
        return [
            PosPayment::METHOD_CASH,
            PosPayment::METHOD_CARD,
            PosPayment::METHOD_MOBILE_PAYMENT,
            PosPayment::METHOD_TRANSFER,
            PosPayment::METHOD_ZELLE,
            PosPayment::METHOD_EXTERNAL_FINANCING,
            PosPayment::METHOD_OTHER,
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del método de pago es obligatorio.',
            'code.required' => 'El código del método de pago es obligatorio.',
            'code.unique' => 'Ya existe un método de pago con este código en la empresa actual.',
            'currency_mode.in' => 'La moneda permitida debe ser USD, VES o flexible.',
        ];
    }
}
