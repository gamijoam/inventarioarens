<?php

namespace App\Modules\Suppliers\Services;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Currency\Services\SettlementRateResolver;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierCreditTransaction;
use App\Modules\Sync\Services\SyncOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Credito con proveedor. El saldo disponible es la suma del libro en USD:
 * devoluciones posteriores al pago suman; aplicaciones a CxP y reembolsos
 * recibidos restan.
 */
class SupplierCreditService
{
    private const TOLERANCE = 0.0001;

    public function __construct(private readonly SyncOutboxService $syncOutbox) {}

    public function availableBase(Supplier $supplier): float
    {
        return round((float) SupplierCreditTransaction::query()
            ->where('supplier_id', $supplier->id)
            ->sum('amount_base'), 4);
    }

    public function availableLocal(Supplier $supplier): float
    {
        return round((float) SupplierCreditTransaction::query()
            ->where('supplier_id', $supplier->id)
            ->sum('amount_local'), 4);
    }

    /**
     * Credito generado por una devolucion: lo devuelto mas lo pagado y ajustado
     * que excede la factura, menos el credito ya reconocido para esa CxP.
     */
    public function recordReturnCredit(PurchaseReturn $purchaseReturn): ?SupplierCreditTransaction
    {
        $account = AccountsPayable::query()
            ->where('purchase_order_id', $purchaseReturn->purchase_order_id)
            ->lockForUpdate()
            ->first();

        if (! $account || ! $account->supplier_id) {
            return null;
        }

        $original = (float) $account->original_base_amount;
        $documentRate = $original > 0.0 ? (float) $account->original_local_amount / $original : 0.0;
        $isLocalDocument = strtoupper((string) $account->currency) === PurchaseOrder::CURRENCY_VES && $documentRate > 0.0;

        $alreadyBase = (float) SupplierCreditTransaction::query()
            ->where('accounts_payable_id', $account->id)
            ->where('type', SupplierCreditTransaction::TYPE_RETURN_CREDIT)
            ->sum('amount_base');

        if ($isLocalDocument) {
            $excessLocal = (float) $account->returned_local_amount + (float) $account->paid_local_amount
                + (float) $account->adjusted_local_amount - (float) $account->original_local_amount;
            $alreadyLocal = (float) SupplierCreditTransaction::query()
                ->where('accounts_payable_id', $account->id)
                ->where('type', SupplierCreditTransaction::TYPE_RETURN_CREDIT)
                ->sum('amount_local');
            $creditLocal = round(max(0.0, $excessLocal) - $alreadyLocal, 4);
            $creditBase = round($creditLocal / $documentRate, 4);
        } else {
            $excessBase = (float) $account->returned_base_amount + (float) $account->paid_base_amount
                + (float) $account->adjusted_base_amount - $original;
            $creditBase = round(max(0.0, $excessBase) - $alreadyBase, 4);
            $documentRate = $documentRate > 0.0 ? $documentRate : $this->officialRate();
            $creditLocal = round($creditBase * $documentRate, 4);
        }

        if ($creditBase <= self::TOLERANCE) {
            return null;
        }

        return $this->store([
            'supplier_id' => $account->supplier_id,
            'type' => SupplierCreditTransaction::TYPE_RETURN_CREDIT,
            'currency' => $isLocalDocument ? PurchaseOrder::CURRENCY_VES : PurchaseOrder::CURRENCY_USD,
            'amount' => $isLocalDocument ? $creditLocal : $creditBase,
            'amount_base' => $creditBase,
            'amount_local' => $creditLocal,
            'exchange_rate_type_id' => $account->exchange_rate_type_id,
            'exchange_rate_type_code' => $account->exchange_rate_type_code,
            'exchange_rate' => $documentRate > 0.0 ? round($documentRate, 6) : null,
            'accounts_payable_id' => $account->id,
            'purchase_return_id' => $purchaseReturn->id,
            'notes' => "Devolucion de compra #{$purchaseReturn->id} posterior al pago",
            'created_by' => $purchaseReturn->created_by,
        ], $account->document_number);
    }

    /**
     * Aplica credito del proveedor a una CxP suya como pago sin movimiento de
     * caja, valorado a la tasa de esa factura.
     */
    public function applyToPayable(AccountsPayable $account, User $user, float $amountBase, ?string $notes = null): AccountsPayablePayment
    {
        return DB::transaction(function () use ($account, $user, $amountBase, $notes): AccountsPayablePayment {
            $account = AccountsPayable::query()->lockForUpdate()->findOrFail($account->id);
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($account->supplier_id);
            $amountBase = round($amountBase, 4);
            $available = $this->availableBase($supplier);

            if ($amountBase <= 0.0 || $amountBase > $available + self::TOLERANCE) {
                throw ValidationException::withMessages([
                    'amount' => sprintf('El credito disponible con este proveedor es $%s; no cubre $%s.', number_format($available, 2, '.', ''), number_format($amountBase, 2, '.', '')),
                ]);
            }

            if ($amountBase > (float) $account->balance_base_amount + self::TOLERANCE) {
                throw ValidationException::withMessages([
                    'amount' => sprintf('El monto supera el saldo pendiente de la cuenta ($%s).', number_format((float) $account->balance_base_amount, 2, '.', '')),
                ]);
            }

            $original = (float) $account->original_base_amount;
            $documentRate = $original > 0.0 && (float) $account->original_local_amount > 0.0
                ? round((float) $account->original_local_amount / $original, 6)
                : $this->officialRate();
            $amountLocal = $documentRate > 0.0 ? round($amountBase * $documentRate, 4) : 0.0;
            $documentRate = $documentRate > 0.0 ? $documentRate : null;

            $payment = app(AccountsPayableService::class)
                ->registerCreditPayment($account, $user, $amountBase, $amountLocal, $documentRate, $notes);

            $this->store([
                'supplier_id' => $supplier->id,
                'type' => SupplierCreditTransaction::TYPE_APPLIED,
                'currency' => PurchaseOrder::CURRENCY_USD,
                'amount' => -$amountBase,
                'amount_base' => -$amountBase,
                'amount_local' => -$amountLocal,
                'exchange_rate_type_id' => $account->exchange_rate_type_id,
                'exchange_rate_type_code' => $account->exchange_rate_type_code,
                'exchange_rate' => $documentRate,
                'accounts_payable_id' => $account->id,
                'accounts_payable_payment_id' => $payment->id,
                'notes' => $notes ?? "Credito aplicado a {$account->document_number}",
                'created_by' => $user->id,
            ], $account->document_number);

            return $payment;
        });
    }

    public function recordRefund(Supplier $supplier, User $user, array $data): SupplierCreditTransaction
    {
        return DB::transaction(function () use ($supplier, $user, $data): SupplierCreditTransaction {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $method = $data['method'] ?? null;

            if ($method === CashRegisterMovement::METHOD_CASH && empty($data['cash_register_session_id'])) {
                throw ValidationException::withMessages([
                    'cash_register_session_id' => 'El reembolso en efectivo requiere una caja abierta.',
                ]);
            }

            [$rateType, $rate, $amountBase, $amountLocal] = app(SettlementRateResolver::class)->resolve(
                $data['payment_currency'],
                (float) $data['amount'],
                $data['exchange_rate_type_id'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
                'El reembolso',
            );

            $available = $this->availableBase($supplier);

            if ($amountBase <= 0.0 || $amountBase > $available + 0.01) {
                throw ValidationException::withMessages([
                    'amount' => sprintf('El credito disponible con este proveedor es $%s; el reembolso equivale a $%s.', number_format($available, 2, '.', ''), number_format($amountBase, 2, '.', '')),
                ]);
            }

            $transaction = $this->store([
                'supplier_id' => $supplier->id,
                'type' => SupplierCreditTransaction::TYPE_REFUND_RECEIVED,
                'currency' => strtoupper($data['payment_currency']),
                'amount' => -abs((float) $data['amount']),
                'amount_base' => -$amountBase,
                'amount_local' => -$amountLocal,
                'exchange_rate_type_id' => $rateType?->id,
                'exchange_rate_type_code' => $rateType?->code,
                'exchange_rate' => $rate,
                'method' => $method,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if ($method === CashRegisterMovement::METHOD_CASH) {
                $session = CashRegisterSession::query()->findOrFail($data['cash_register_session_id']);
                app(CashRegisterService::class)->recordSupplierCreditRefund($session, $transaction, $user);
            }

            return $transaction;
        });
    }

    public function summary(Supplier $supplier): array
    {
        return [
            'supplier_id' => $supplier->id,
            'available_base_amount' => $this->availableBase($supplier),
            'available_local_amount' => $this->availableLocal($supplier),
            'transactions' => SupplierCreditTransaction::query()
                ->where('supplier_id', $supplier->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (SupplierCreditTransaction $row): array => [
                    'id' => $row->id,
                    'type' => $row->type,
                    'currency' => $row->currency,
                    'amount' => (float) $row->amount,
                    'amount_base' => (float) $row->amount_base,
                    'amount_local' => (float) $row->amount_local,
                    'exchange_rate' => $row->exchange_rate === null ? null : (float) $row->exchange_rate,
                    'accounts_payable_id' => $row->accounts_payable_id,
                    'purchase_return_id' => $row->purchase_return_id,
                    'method' => $row->method,
                    'reference' => $row->reference,
                    'notes' => $row->notes,
                    'occurred_at' => $row->occurred_at?->toISOString(),
                    'created_at' => $row->created_at?->toISOString(),
                ])
                ->all(),
        ];
    }

    /**
     * Factura en USD sin tasa registrada: el equivalente en Bs se toma a la
     * tasa oficial vigente del tipo por defecto.
     */
    private function officialRate(): float
    {
        return (float) (app(SettlementRateResolver::class)->resolve(PurchaseOrder::CURRENCY_USD, 1.0, null, null, 'El credito')[1] ?? 0.0);
    }

    private function store(array $attributes, ?string $payableDocument = null): SupplierCreditTransaction
    {
        $attributes['occurred_at'] ??= now();
        $transaction = SupplierCreditTransaction::create($attributes);
        $supplier = $transaction->supplier;

        $this->syncOutbox->record(
            eventType: 'supplier_credit.recorded',
            aggregateType: 'supplier_credit',
            aggregateId: $transaction->id,
            payload: [
                'transaction_id' => $transaction->id,
                'supplier_document_type' => $supplier?->document_type,
                'supplier_document_number' => $supplier?->document_number,
                'payable_document' => $payableDocument,
                'type' => $transaction->type,
                'currency' => $transaction->currency,
                'amount' => (string) $transaction->amount,
                'amount_base' => (string) $transaction->amount_base,
                'amount_local' => (string) $transaction->amount_local,
                'exchange_rate_type_code' => $transaction->exchange_rate_type_code,
                'exchange_rate' => $transaction->exchange_rate === null ? null : (string) $transaction->exchange_rate,
                'method' => $transaction->method,
                'reference' => $transaction->reference,
                'notes' => $transaction->notes,
                'occurred_at' => $transaction->occurred_at?->toJSON(),
            ],
            idempotencyKey: "supplier_credit.recorded:supplier_credit:{$transaction->id}"
        );

        return $transaction;
    }
}
