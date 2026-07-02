<?php

namespace App\Modules\AccountsPayable\Services;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\Purchases\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountsPayableService
{
    public function createForPurchase(PurchaseOrder $purchaseOrder): AccountsPayable
    {
        return DB::transaction(function () use ($purchaseOrder): AccountsPayable {
            $purchaseOrder = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->id);

            if ($purchaseOrder->status !== PurchaseOrder::STATUS_RECEIVED) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => 'La cuenta por pagar solo se crea para compras recibidas.',
                ]);
            }

            return AccountsPayable::query()->firstOrCreate(
                ['purchase_order_id' => $purchaseOrder->id],
                [
                    'supplier_id' => $purchaseOrder->supplier_id,
                    'status' => AccountsPayable::STATUS_PENDING,
                    'document_number' => $purchaseOrder->document_number,
                    'currency' => $purchaseOrder->purchase_currency,
                    'exchange_rate_type_id' => $purchaseOrder->exchange_rate_type_id,
                    'exchange_rate_type_code' => $purchaseOrder->exchange_rate_type_code,
                    'exchange_rate' => $purchaseOrder->exchange_rate,
                    'original_base_amount' => $purchaseOrder->total_base_amount,
                    'original_local_amount' => $purchaseOrder->total_local_amount,
                    'balance_base_amount' => $purchaseOrder->total_base_amount,
                    'balance_local_amount' => $purchaseOrder->total_local_amount,
                    'opened_at' => now(),
                ]
            )->refresh();
        });
    }

    public function applyPurchaseReturn(PurchaseReturn $purchaseReturn): ?AccountsPayable
    {
        return DB::transaction(function () use ($purchaseReturn): ?AccountsPayable {
            $purchaseReturn = PurchaseReturn::query()
                ->with('purchaseOrder')
                ->lockForUpdate()
                ->findOrFail($purchaseReturn->id);

            $account = AccountsPayable::query()
                ->where('purchase_order_id', $purchaseReturn->purchase_order_id)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                return null;
            }

            [$returnedBase, $returnedLocal] = $this->returnedTotalsForPurchase($purchaseReturn->purchaseOrder);

            $account->returned_base_amount = $returnedBase;
            $account->returned_local_amount = $returnedLocal;
            $this->recalculate($account);
            $account->save();

            return $account->refresh()->load(['supplier', 'purchaseOrder', 'payments']);
        });
    }

    public function registerPayment(AccountsPayable $account, User $user, array $data): AccountsPayablePayment
    {
        return DB::transaction(function () use ($account, $user, $data): AccountsPayablePayment {
            $account = AccountsPayable::query()
                ->lockForUpdate()
                ->findOrFail($account->id);

            if ((float) $account->balance_base_amount <= 0.0) {
                throw ValidationException::withMessages([
                    'amount' => 'La cuenta por pagar ya esta pagada.',
                ]);
            }

            [$rateType, $exchangeRate, $amountBase, $amountLocal] = $this->paymentAmounts(
                $data['payment_currency'],
                (float) $data['amount'],
                $data['exchange_rate_type_id'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            );

            if ($amountBase > round((float) $account->balance_base_amount, 4)) {
                throw ValidationException::withMessages([
                    'amount' => 'El pago supera el saldo pendiente de la cuenta.',
                ]);
            }

            $payment = AccountsPayablePayment::create([
                'accounts_payable_id' => $account->id,
                'payment_currency' => $data['payment_currency'],
                'amount' => $data['amount'],
                'exchange_rate_type_id' => $rateType?->id,
                'exchange_rate_type_code' => $rateType?->code,
                'exchange_rate' => $exchangeRate,
                'amount_base' => $amountBase,
                'amount_local' => $amountLocal,
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'paid_at' => $data['paid_at'] ?? now(),
            ]);

            $account->paid_base_amount = round((float) $account->paid_base_amount + $amountBase, 4);
            $account->paid_local_amount = round((float) $account->paid_local_amount + $amountLocal, 4);
            $this->recalculate($account);
            $account->save();

            return $payment->refresh()->load('account');
        });
    }

    private function paymentAmounts(string $currency, float $amount, ?int $rateTypeId, ?float $exchangeRate): array
    {
        if ($currency === PurchaseOrder::CURRENCY_USD) {
            $rateType = $rateTypeId ? ExchangeRateType::query()->findOrFail($rateTypeId) : null;
            $rate = $exchangeRate ?: ($rateType ? $this->activeRateFor($rateType)?->rate : null);

            return [$rateType, $rate ? (float) $rate : null, round($amount, 4), $rate ? round($amount * (float) $rate, 4) : 0.0];
        }

        $rateType = $rateTypeId
            ? ExchangeRateType::query()->findOrFail($rateTypeId)
            : ExchangeRateType::query()->where('is_default', true)->where('is_active', true)->first();

        if (! $rateType) {
            throw ValidationException::withMessages([
                'exchange_rate_type_id' => 'El pago en bolivares requiere un tipo de tasa activo.',
            ]);
        }

        $rate = $exchangeRate ?: $this->activeRateFor($rateType)?->rate;

        if (! $rate || (float) $rate <= 0.0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'El pago en bolivares requiere una tasa activa mayor que cero.',
            ]);
        }

        return [$rateType, (float) $rate, round($amount / (float) $rate, 4), round($amount, 4)];
    }

    private function activeRateFor(ExchangeRateType $rateType): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('exchange_rate_type_id', $rateType->id)
            ->where('base_currency', ExchangeRate::BASE_USD)
            ->where('quote_currency', ExchangeRate::QUOTE_VES)
            ->where('is_active', true)
            ->latest('effective_at')
            ->first();
    }

    private function localReturnAmount(PurchaseOrder $purchaseOrder, float $unitCost, float $quantity): float
    {
        if ($purchaseOrder->purchase_currency === PurchaseOrder::CURRENCY_VES) {
            return round($unitCost * $quantity, 4);
        }

        if (! $purchaseOrder->exchange_rate) {
            return 0.0;
        }

        return round($unitCost * $quantity * (float) $purchaseOrder->exchange_rate, 4);
    }

    private function returnedTotalsForPurchase(PurchaseOrder $purchaseOrder): array
    {
        $returns = PurchaseReturn::query()
            ->with('items.purchaseItem')
            ->where('purchase_order_id', $purchaseOrder->id)
            ->get();

        $returnedBase = 0.0;
        $returnedLocal = 0.0;

        foreach ($returns as $return) {
            foreach ($return->items as $item) {
                $purchaseItem = $item->purchaseItem;
                $quantity = (float) $item->quantity;
                $returnedBase += round((float) $purchaseItem->base_unit_cost * $quantity, 4);
                $returnedLocal += $this->localReturnAmount($purchaseOrder, (float) $purchaseItem->unit_cost, $quantity);
            }
        }

        return [round($returnedBase, 4), round($returnedLocal, 4)];
    }

    private function recalculate(AccountsPayable $account): void
    {
        $account->balance_base_amount = max(0.0, round(
            (float) $account->original_base_amount
            - (float) $account->returned_base_amount
            - (float) $account->paid_base_amount,
            4
        ));

        $account->balance_local_amount = max(0.0, round(
            (float) $account->original_local_amount
            - (float) $account->returned_local_amount
            - (float) $account->paid_local_amount,
            4
        ));

        if ((float) $account->balance_base_amount <= 0.0) {
            $account->status = AccountsPayable::STATUS_PAID;
            $account->paid_at = $account->paid_at ?? now();

            return;
        }

        $account->paid_at = null;

        if ($account->due_date && $account->due_date->isPast()) {
            $account->status = AccountsPayable::STATUS_OVERDUE;

            return;
        }

        $account->status = (float) $account->paid_base_amount > 0.0
            ? AccountsPayable::STATUS_PARTIAL
            : AccountsPayable::STATUS_PENDING;
    }
}
