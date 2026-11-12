<?php

namespace App\Modules\Currency\Services;

use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Models\AccountsReceivablePayment;
use App\Modules\Currency\Models\ExchangeDifference;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncOutboxService;

/**
 * Registra el diferencial cambiario de cobros y pagos. Signo: positivo es
 * ganancia de la empresa, negativo es perdida.
 *
 * - Deuda en USD: la diferencia es en Bs, monto aplicado x (tasa de pago -
 *   tasa del documento); para CxP el signo se invierte.
 * - CxP en Bs: la deuda se salda por su monto en Bs; la diferencia es en USD,
 *   valor aplicado a tasa de la factura menos valor real pagado.
 */
class ExchangeDifferenceService
{
    private const MIN_LOCAL_DIFFERENCE = 0.01;

    private const MIN_BASE_DIFFERENCE = 0.0001;

    public function __construct(private readonly SyncOutboxService $syncOutbox) {}

    public function recordReceivablePayment(AccountsReceivable $account, AccountsReceivablePayment $payment): ?ExchangeDifference
    {
        $documentRate = $this->documentRate($account->original_base_amount, $account->original_local_amount);
        $settlementRate = $payment->exchange_rate === null ? null : (float) $payment->exchange_rate;

        if (! $documentRate || ! $settlementRate) {
            return null;
        }

        $applied = (float) $payment->amount_base;
        $differenceLocal = round($applied * ($settlementRate - $documentRate), 4);

        if (abs($differenceLocal) < self::MIN_LOCAL_DIFFERENCE) {
            return null;
        }

        return $this->store([
            'account_type' => ExchangeDifference::ACCOUNT_RECEIVABLE,
            'accounts_receivable_id' => $account->id,
            'document_number' => $account->document_number,
            'payment_type' => AccountsReceivablePayment::class,
            'payment_id' => $payment->id,
            'document_currency' => Product::CURRENCY_USD,
            'payment_currency' => strtoupper((string) $payment->payment_currency),
            'document_rate' => $documentRate,
            'settlement_rate' => $settlementRate,
            'applied_base_amount' => $applied,
            'applied_local_amount' => round($applied * $documentRate, 4),
            'paid_base_amount' => $applied,
            'paid_local_amount' => (float) $payment->amount_local,
            'difference_base_amount' => round($differenceLocal / $settlementRate, 4),
            'difference_local_amount' => $differenceLocal,
            'occurred_at' => $payment->paid_at ?? now(),
        ]);
    }

    public function recordPayablePayment(
        AccountsPayable $account,
        AccountsPayablePayment $payment,
        float $appliedBase,
        float $appliedLocal,
    ): ?ExchangeDifference {
        $documentRate = $this->documentRate($account->original_base_amount, $account->original_local_amount);
        $settlementRate = $payment->exchange_rate === null ? null : (float) $payment->exchange_rate;

        if (! $documentRate || ! $settlementRate) {
            return null;
        }

        if (strtoupper((string) $account->currency) === Product::CURRENCY_VES) {
            $differenceBase = round($appliedBase - (float) $payment->amount_base, 4);

            if (abs($differenceBase) < self::MIN_BASE_DIFFERENCE) {
                return null;
            }

            $differenceLocal = 0.0;
        } else {
            $differenceLocal = round(-$appliedBase * ($settlementRate - $documentRate), 4);

            if (abs($differenceLocal) < self::MIN_LOCAL_DIFFERENCE) {
                return null;
            }

            $differenceBase = round($differenceLocal / $settlementRate, 4);
        }

        return $this->store([
            'account_type' => ExchangeDifference::ACCOUNT_PAYABLE,
            'accounts_payable_id' => $account->id,
            'document_number' => $account->document_number,
            'payment_type' => AccountsPayablePayment::class,
            'payment_id' => $payment->id,
            'document_currency' => strtoupper((string) ($account->currency ?: Product::CURRENCY_USD)),
            'payment_currency' => strtoupper((string) $payment->payment_currency),
            'document_rate' => $documentRate,
            'settlement_rate' => $settlementRate,
            'applied_base_amount' => $appliedBase,
            'applied_local_amount' => $appliedLocal,
            'paid_base_amount' => (float) $payment->amount_base,
            'paid_local_amount' => (float) $payment->amount_local,
            'difference_base_amount' => $differenceBase,
            'difference_local_amount' => $differenceLocal,
            'occurred_at' => $payment->paid_at ?? now(),
        ]);
    }

    private function store(array $attributes): ExchangeDifference
    {
        $attributes['kind'] = ($attributes['difference_local_amount'] ?: $attributes['difference_base_amount']) >= 0
            ? ExchangeDifference::KIND_GAIN
            : ExchangeDifference::KIND_LOSS;

        $difference = ExchangeDifference::create($attributes);
        $this->recordSyncEvent($difference);

        return $difference;
    }

    private function documentRate(mixed $base, mixed $local): ?float
    {
        $base = (float) $base;
        $local = (float) $local;

        return $base > 0.0 && $local > 0.0 ? round($local / $base, 6) : null;
    }

    private function recordSyncEvent(ExchangeDifference $difference): void
    {
        $this->syncOutbox->record(
            eventType: 'exchange_difference.recorded',
            aggregateType: 'exchange_difference',
            aggregateId: $difference->id,
            payload: [
                'difference_id' => $difference->id,
                'account_type' => $difference->account_type,
                'document_number' => $difference->document_number,
                'document_currency' => $difference->document_currency,
                'payment_currency' => $difference->payment_currency,
                'document_rate' => (string) $difference->document_rate,
                'settlement_rate' => (string) $difference->settlement_rate,
                'applied_base_amount' => (string) $difference->applied_base_amount,
                'applied_local_amount' => (string) $difference->applied_local_amount,
                'paid_base_amount' => (string) $difference->paid_base_amount,
                'paid_local_amount' => (string) $difference->paid_local_amount,
                'difference_base_amount' => (string) $difference->difference_base_amount,
                'difference_local_amount' => (string) $difference->difference_local_amount,
                'kind' => $difference->kind,
                'occurred_at' => $difference->occurred_at?->toJSON(),
            ],
            idempotencyKey: "exchange_difference.recorded:exchange_difference:{$difference->id}"
        );
    }
}
