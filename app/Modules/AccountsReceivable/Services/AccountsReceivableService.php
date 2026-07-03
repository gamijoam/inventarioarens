<?php

namespace App\Modules\AccountsReceivable\Services;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Models\AccountsReceivablePayment;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentReceipts\Services\PaymentReceiptService;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\SalesReturns\Models\SalesReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountsReceivableService
{
    public function createForSale(Sale $sale): AccountsReceivable
    {
        return DB::transaction(function () use ($sale): AccountsReceivable {
            $sale = Sale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            if ($sale->status !== Sale::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'sale_id' => 'La cuenta por cobrar solo se crea para ventas confirmadas.',
                ]);
            }

            return AccountsReceivable::query()->firstOrCreate(
                ['sale_id' => $sale->id],
                [
                    'customer_id' => $sale->customer_id,
                    'status' => AccountsReceivable::STATUS_PENDING,
                    'document_number' => "VENTA-{$sale->id}",
                    'currency' => Product::CURRENCY_USD,
                    'original_base_amount' => $sale->total_base_amount,
                    'original_local_amount' => $sale->total_local_amount,
                    'balance_base_amount' => $sale->total_base_amount,
                    'balance_local_amount' => $sale->total_local_amount,
                    'opened_at' => now(),
                ]
            )->refresh();
        });
    }

    public function applySalesReturn(SalesReturn $salesReturn): ?AccountsReceivable
    {
        return DB::transaction(function () use ($salesReturn): ?AccountsReceivable {
            $salesReturn = SalesReturn::query()
                ->with('sale')
                ->lockForUpdate()
                ->findOrFail($salesReturn->id);

            $account = AccountsReceivable::query()
                ->where('sale_id', $salesReturn->sale_id)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                return null;
            }

            [$returnedBase, $returnedLocal] = $this->returnedTotalsForSale($salesReturn->sale);

            $account->returned_base_amount = $returnedBase;
            $account->returned_local_amount = $returnedLocal;
            $this->recalculate($account);
            $account->save();

            return $account->refresh()->load(['customer', 'sale', 'payments']);
        });
    }

    public function registerPayment(AccountsReceivable $account, User $user, array $data): AccountsReceivablePayment
    {
        return DB::transaction(function () use ($account, $user, $data): AccountsReceivablePayment {
            $account = AccountsReceivable::query()
                ->lockForUpdate()
                ->findOrFail($account->id);

            if ((float) $account->balance_base_amount <= 0.0) {
                throw ValidationException::withMessages([
                    'amount' => 'La cuenta por cobrar ya esta pagada.',
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
                    'amount' => 'El cobro supera el saldo pendiente de la cuenta.',
                ]);
            }

            $payment = AccountsReceivablePayment::create([
                'accounts_receivable_id' => $account->id,
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

            $account->collected_base_amount = round((float) $account->collected_base_amount + $amountBase, 4);
            $account->collected_local_amount = round((float) $account->collected_local_amount + $amountLocal, 4);
            $this->recalculate($account);
            $account->save();

            app(PaymentReceiptService::class)->issueForReceivablePayment($payment, $user);

            return $payment->refresh()->load('account');
        });
    }

    public function registerPosPayment(AccountsReceivable $account, User $user, PosPayment $posPayment): AccountsReceivablePayment
    {
        $reference = "POS-PAYMENT-{$posPayment->id}";

        $existing = AccountsReceivablePayment::query()
            ->where('accounts_receivable_id', $account->id)
            ->where('reference', $reference)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->registerPayment($account, $user, [
            'payment_currency' => $posPayment->currency,
            'amount' => $posPayment->amount,
            'exchange_rate_type_id' => $posPayment->exchange_rate_type_id,
            'exchange_rate' => $posPayment->exchange_rate ? (float) $posPayment->exchange_rate : null,
            'method' => "pos_{$posPayment->method}",
            'reference' => $reference,
            'notes' => 'Cobro generado automaticamente desde POS.',
            'paid_at' => $posPayment->created_at ?? now(),
        ]);
    }

    private function paymentAmounts(string $currency, float $amount, ?int $rateTypeId, ?float $exchangeRate): array
    {
        if ($currency === Product::CURRENCY_USD) {
            $rateType = $rateTypeId ? ExchangeRateType::query()->findOrFail($rateTypeId) : null;
            $rate = $exchangeRate ?: ($rateType ? $this->activeRateFor($rateType)?->rate : null);

            return [$rateType, $rate ? (float) $rate : null, round($amount, 4), $rate ? round($amount * (float) $rate, 4) : 0.0];
        }

        $rateType = $rateTypeId
            ? ExchangeRateType::query()->findOrFail($rateTypeId)
            : ExchangeRateType::query()->where('is_default', true)->where('is_active', true)->first();

        if (! $rateType) {
            throw ValidationException::withMessages([
                'exchange_rate_type_id' => 'El cobro en bolivares requiere un tipo de tasa activo.',
            ]);
        }

        $rate = $exchangeRate ?: $this->activeRateFor($rateType)?->rate;

        if (! $rate || (float) $rate <= 0.0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'El cobro en bolivares requiere una tasa activa mayor que cero.',
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

    private function returnedTotalsForSale(Sale $sale): array
    {
        $returns = SalesReturn::query()
            ->with('items.saleItem')
            ->where('sale_id', $sale->id)
            ->get();

        $returnedBase = 0.0;
        $returnedLocal = 0.0;

        foreach ($returns as $return) {
            foreach ($return->items as $item) {
                $saleItem = $item->saleItem;
                $quantity = (float) $item->quantity;
                $returnedBase += round((float) $saleItem->base_unit_price * $quantity, 4);
                $returnedLocal += $this->localReturnAmount($saleItem, $quantity);
            }
        }

        return [round($returnedBase, 4), round($returnedLocal, 4)];
    }

    private function localReturnAmount($saleItem, float $quantity): float
    {
        if ($saleItem->exchange_rate) {
            return round((float) $saleItem->base_unit_price * $quantity * (float) $saleItem->exchange_rate, 4);
        }

        return $saleItem->sale_currency === Product::CURRENCY_VES
            ? round((float) $saleItem->unit_price * $quantity, 4)
            : 0.0;
    }

    private function recalculate(AccountsReceivable $account): void
    {
        $account->balance_base_amount = max(0.0, round(
            (float) $account->original_base_amount
            - (float) $account->returned_base_amount
            - (float) $account->collected_base_amount
            - (float) $account->adjusted_base_amount,
            4
        ));

        $account->balance_local_amount = max(0.0, round(
            (float) $account->original_local_amount
            - (float) $account->returned_local_amount
            - (float) $account->collected_local_amount
            - (float) $account->adjusted_local_amount,
            4
        ));

        if ((float) $account->balance_base_amount <= 0.0) {
            $account->status = AccountsReceivable::STATUS_PAID;
            $account->paid_at = $account->paid_at ?? now();

            return;
        }

        $account->paid_at = null;

        if ($account->due_date && $account->due_date->isPast()) {
            $account->status = AccountsReceivable::STATUS_OVERDUE;

            return;
        }

        $account->status = ((float) $account->collected_base_amount > 0.0 || (float) $account->adjusted_base_amount > 0.0)
            ? AccountsReceivable::STATUS_PARTIAL
            : AccountsReceivable::STATUS_PENDING;
    }
}
