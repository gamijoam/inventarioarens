<?php

namespace App\Modules\SalesReturns\Requests;

use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessSalesReturnRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'process_notes' => ['nullable', 'string'],
            'refund_mode' => ['nullable', 'string', Rule::in(['none', 'cash', 'receivable'])],
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
                Rule::exists('exchange_rate_types', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'refund_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'refund_cash_register_session_id' => [
                'nullable',
                'integer',
                Rule::exists('cash_register_sessions', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
