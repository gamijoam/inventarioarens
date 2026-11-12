<?php

namespace App\Modules\AccountsPayable\Services;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Currency\Services\ExchangeDifferenceService;
use App\Modules\Currency\Services\SettlementRateResolver;
use App\Modules\PaymentReceipts\Services\PaymentReceiptService;
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

            if (! in_array($purchaseOrder->status, [PurchaseOrder::STATUS_PARTIALLY_RECEIVED, PurchaseOrder::STATUS_RECEIVED], true)) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => 'La cuenta por pagar solo se crea para compras recibidas parcial o totalmente.',
                ]);
            }

            $account = AccountsPayable::query()->firstOrCreate(
                ['purchase_order_id' => $purchaseOrder->id],
                [
                    'supplier_id' => $purchaseOrder->supplier_id,
                    'status' => AccountsPayable::STATUS_PENDING,
                    'document_number' => $purchaseOrder->document_number,
                    'currency' => $purchaseOrder->purchase_currency,
                    'exchange_rate_type_id' => $purchaseOrder->exchange_rate_type_id,
                    'exchange_rate_type_code' => $purchaseOrder->exchange_rate_type_code,
                    'exchange_rate' => $purchaseOrder->exchange_rate,
                    'original_base_amount' => $purchaseOrder->received_base_amount,
                    'original_local_amount' => $purchaseOrder->received_local_amount,
                    'balance_base_amount' => $purchaseOrder->received_base_amount,
                    'balance_local_amount' => $purchaseOrder->received_local_amount,
                    'due_date' => $purchaseOrder->due_date,
                    'opened_at' => now(),
                ]
            );

            $account->fill([
                'supplier_id' => $purchaseOrder->supplier_id,
                'document_number' => $purchaseOrder->document_number,
                'currency' => $purchaseOrder->purchase_currency,
                'exchange_rate_type_id' => $purchaseOrder->exchange_rate_type_id,
                'exchange_rate_type_code' => $purchaseOrder->exchange_rate_type_code,
                'exchange_rate' => $purchaseOrder->exchange_rate,
                'original_base_amount' => $purchaseOrder->received_base_amount,
                'original_local_amount' => $purchaseOrder->received_local_amount,
                'due_date' => $purchaseOrder->due_date,
            ]);
            $this->recalculate($account);
            $account->save();

            return $account->refresh();
        });
    }

    public function syncPurchaseMetadata(PurchaseOrder $purchaseOrder): ?AccountsPayable
    {
        return DB::transaction(function () use ($purchaseOrder): ?AccountsPayable {
            $account = AccountsPayable::query()
                ->where('purchase_order_id', $purchaseOrder->id)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                return null;
            }

            $account->fill([
                'supplier_id' => $purchaseOrder->supplier_id,
                'document_number' => $purchaseOrder->document_number,
                'due_date' => $purchaseOrder->due_date,
            ]);

            $this->recalculate($account);
            $account->save();

            return $account->refresh();
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

            if (($data['method'] ?? null) === CashRegisterMovement::METHOD_CASH && empty($data['cash_register_session_id'])) {
                throw ValidationException::withMessages([
                    'cash_register_session_id' => 'El pago en efectivo requiere una caja abierta.',
                ]);
            }

            [$rateType, $exchangeRate, $amountBase, $amountLocal] = $this->paymentAmounts(
                $data['payment_currency'],
                (float) $data['amount'],
                $data['exchange_rate_type_id'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            );

            [$appliedBase, $appliedLocal] = $this->appliedAmounts($account, $amountBase, $amountLocal);

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

            $account->paid_base_amount = round((float) $account->paid_base_amount + $appliedBase, 4);
            $account->paid_local_amount = round((float) $account->paid_local_amount + $appliedLocal, 4);
            $this->recalculate($account);
            $account->save();

            app(ExchangeDifferenceService::class)->recordPayablePayment($account, $payment, $appliedBase, $appliedLocal);

            app(PaymentReceiptService::class)->issueForPayablePayment($payment, $user);

            if (($data['method'] ?? null) === CashRegisterMovement::METHOD_CASH) {
                $session = CashRegisterSession::query()->findOrFail($data['cash_register_session_id']);
                app(CashRegisterService::class)->recordPayablePayment($session, $payment, $user);
            }

            return $payment->refresh()->load('account');
        });
    }

    /**
     * Monto que el pago cancela de la deuda. Una factura en bolivares se salda
     * por su monto en Bs y su equivalente USD a la tasa de la factura; la
     * diferencia con el valor real pagado es diferencial cambiario.
     *
     * @return array{0: float, 1: float}
     */
    private function appliedAmounts(AccountsPayable $account, float $amountBase, float $amountLocal): array
    {
        $isBolivarDebt = strtoupper((string) $account->currency) === PurchaseOrder::CURRENCY_VES
            && (float) $account->original_base_amount > 0.0
            && (float) $account->original_local_amount > 0.0;

        if (! $isBolivarDebt) {
            if ($amountBase > round((float) $account->balance_base_amount, 4)) {
                throw ValidationException::withMessages([
                    'amount' => 'El pago supera el saldo pendiente de la cuenta.',
                ]);
            }

            return [$amountBase, $amountLocal];
        }

        if ($amountLocal > round((float) $account->balance_local_amount, 4) + 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'El pago supera el saldo pendiente en bolivares de la cuenta.',
            ]);
        }

        $appliedLocal = min($amountLocal, (float) $account->balance_local_amount);
        $documentRate = (float) $account->original_local_amount / (float) $account->original_base_amount;

        return [round($appliedLocal / $documentRate, 4), round($appliedLocal, 4)];
    }

    private function paymentAmounts(string $currency, float $amount, ?int $rateTypeId, ?float $exchangeRate): array
    {
        return app(SettlementRateResolver::class)->resolve($currency, $amount, $rateTypeId, $exchangeRate, 'El pago');
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
        // Factura en bolivares saldada en Bs: el residuo en USD por redondeo de
        // la tasa no deja la cuenta abierta.
        if (strtoupper((string) $account->currency) === PurchaseOrder::CURRENCY_VES
            && (float) $account->original_local_amount > 0.0
            && round((float) $account->original_local_amount - (float) $account->returned_local_amount - (float) $account->paid_local_amount - (float) $account->adjusted_local_amount, 4) < 0.01) {
            $account->balance_base_amount = 0;
            $account->balance_local_amount = 0;
            $account->status = AccountsPayable::STATUS_PAID;
            $account->paid_at = $account->paid_at ?? now();

            return;
        }

        $account->balance_base_amount = max(0.0, round(
            (float) $account->original_base_amount
            - (float) $account->returned_base_amount
            - (float) $account->paid_base_amount
            - (float) $account->adjusted_base_amount,
            4
        ));

        $account->balance_local_amount = max(0.0, round(
            (float) $account->original_local_amount
            - (float) $account->returned_local_amount
            - (float) $account->paid_local_amount
            - (float) $account->adjusted_local_amount,
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

        $account->status = ((float) $account->paid_base_amount > 0.0 || (float) $account->adjusted_base_amount > 0.0)
            ? AccountsPayable::STATUS_PARTIAL
            : AccountsPayable::STATUS_PENDING;
    }
}
