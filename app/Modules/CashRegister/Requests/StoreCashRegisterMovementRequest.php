<?php

namespace App\Modules\CashRegister\Requests;

use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashRegisterMovementRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'type' => [
                'required',
                'string',
                Rule::in([CashRegisterMovement::TYPE_INFLOW, CashRegisterMovement::TYPE_OUTFLOW, CashRegisterMovement::TYPE_ADJUSTMENT]),
            ],
            'method' => [
                'required',
                'string',
                Rule::in([
                    CashRegisterMovement::METHOD_CASH,
                    CashRegisterMovement::METHOD_CARD,
                    CashRegisterMovement::METHOD_MOBILE_PAYMENT,
                    CashRegisterMovement::METHOD_TRANSFER,
                    CashRegisterMovement::METHOD_ZELLE,
                    CashRegisterMovement::METHOD_EXTERNAL_FINANCING,
                    CashRegisterMovement::METHOD_OTHER,
                ]),
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::in([Product::CURRENCY_USD, Product::CURRENCY_VES]),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate_type_id' => [
                'nullable',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId),
            ],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
