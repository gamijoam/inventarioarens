<?php

namespace App\Modules\Warranties\Requests;

use App\Modules\Warranties\Models\WarrantyClaim;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveWarrantyClaimRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'resolution_type' => [
                'required',
                'string',
                Rule::in([
                    WarrantyClaim::RESOLUTION_REPLACEMENT,
                    WarrantyClaim::RESOLUTION_REFUND,
                    WarrantyClaim::RESOLUTION_REJECTED,
                ]),
            ],
            'replacement_product_unit_id' => [
                'nullable',
                'integer',
                Rule::exists('product_units', 'id')->where('tenant_id', $tenantId),
            ],
            'replacement_warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'refund_currency' => [
                'nullable',
                'string',
                'size:3',
                Rule::in([Product::CURRENCY_USD, Product::CURRENCY_VES]),
            ],
            'refund_amount' => ['nullable', 'numeric', 'gt:0'],
            'refund_method' => [
                'nullable',
                'string',
                Rule::in([
                    CashRegisterMovement::METHOD_CASH,
                    CashRegisterMovement::METHOD_CARD,
                    CashRegisterMovement::METHOD_MOBILE_PAYMENT,
                    CashRegisterMovement::METHOD_TRANSFER,
                    CashRegisterMovement::METHOD_ZELLE,
                    CashRegisterMovement::METHOD_OTHER,
                ]),
            ],
            'refund_reference' => ['nullable', 'string', 'max:255'],
            'refund_exchange_rate_type_id' => [
                'nullable',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId),
            ],
            'refund_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'refund_cash_register_session_id' => [
                'nullable',
                'integer',
                Rule::exists('cash_register_sessions', 'id')->where('tenant_id', $tenantId),
            ],
            'apply_to_receivable_balance' => ['nullable', 'boolean'],
            'resolution_notes' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
