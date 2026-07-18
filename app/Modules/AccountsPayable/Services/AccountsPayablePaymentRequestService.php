<?php

namespace App\Modules\AccountsPayable\Services;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePaymentRequest;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Purchases\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountsPayablePaymentRequestService
{
    public function prepare(AccountsPayable $account, User $user, array $data): AccountsPayablePaymentRequest
    {
        return DB::transaction(function () use ($account, $user, $data): AccountsPayablePaymentRequest {
            $account = AccountsPayable::query()
                ->lockForUpdate()
                ->findOrFail($account->id);

            $this->assertOpenAccount($account);

            [$rateType, $exchangeRate, $amountBase, $amountLocal] = $this->paymentAmounts(
                $data['payment_currency'],
                (float) $data['amount'],
                $data['exchange_rate_type_id'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            );

            $this->assertAvailableBalance($account, $amountBase);

            $request = AccountsPayablePaymentRequest::create([
                'accounts_payable_id' => $account->id,
                'status' => $this->requiresApproval($user, $amountBase, $data['method'] ?? null)
                    ? AccountsPayablePaymentRequest::STATUS_PREPARED
                    : AccountsPayablePaymentRequest::STATUS_APPROVED,
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
                'scheduled_for' => $data['scheduled_for'] ?? null,
                'cash_register_session_id' => $data['cash_register_session_id'] ?? null,
                'prepared_by' => $user->id,
                'approved_by' => $this->requiresApproval($user, $amountBase, $data['method'] ?? null) ? null : $user->id,
                'prepared_at' => now(),
                'approved_at' => $this->requiresApproval($user, $amountBase, $data['method'] ?? null) ? null : now(),
            ]);

            return $request->refresh()->load(['account.supplier', 'account.purchaseOrder', 'payment']);
        });
    }

    public function approve(AccountsPayablePaymentRequest $request, User $user): AccountsPayablePaymentRequest
    {
        return DB::transaction(function () use ($request, $user): AccountsPayablePaymentRequest {
            $request = AccountsPayablePaymentRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status !== AccountsPayablePaymentRequest::STATUS_PREPARED) {
                throw ValidationException::withMessages(['status' => 'Solo se pueden aprobar solicitudes preparadas.']);
            }

            $request->fill([
                'status' => AccountsPayablePaymentRequest::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            return $request->refresh()->load(['account.supplier', 'account.purchaseOrder', 'payment']);
        });
    }

    public function reject(AccountsPayablePaymentRequest $request, User $user, string $reason): AccountsPayablePaymentRequest
    {
        return DB::transaction(function () use ($request, $user, $reason): AccountsPayablePaymentRequest {
            $request = AccountsPayablePaymentRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! in_array($request->status, [AccountsPayablePaymentRequest::STATUS_PREPARED, AccountsPayablePaymentRequest::STATUS_APPROVED], true)) {
                throw ValidationException::withMessages(['status' => 'Esta solicitud no se puede rechazar.']);
            }

            $request->fill([
                'status' => AccountsPayablePaymentRequest::STATUS_REJECTED,
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            return $request->refresh()->load(['account.supplier', 'account.purchaseOrder', 'payment']);
        });
    }

    public function cancel(AccountsPayablePaymentRequest $request, User $user, string $reason): AccountsPayablePaymentRequest
    {
        return DB::transaction(function () use ($request, $user, $reason): AccountsPayablePaymentRequest {
            $request = AccountsPayablePaymentRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! in_array($request->status, [AccountsPayablePaymentRequest::STATUS_PREPARED, AccountsPayablePaymentRequest::STATUS_APPROVED], true)) {
                throw ValidationException::withMessages(['status' => 'Esta solicitud no se puede cancelar.']);
            }

            $request->fill([
                'status' => AccountsPayablePaymentRequest::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            return $request->refresh()->load(['account.supplier', 'account.purchaseOrder', 'payment']);
        });
    }

    public function execute(AccountsPayablePaymentRequest $request, User $user, array $data = []): AccountsPayablePaymentRequest
    {
        return DB::transaction(function () use ($request, $user, $data): AccountsPayablePaymentRequest {
            $request = AccountsPayablePaymentRequest::query()
                ->with('account')
                ->lockForUpdate()
                ->findOrFail($request->id);

            if ($request->status !== AccountsPayablePaymentRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Solo se pueden ejecutar solicitudes aprobadas.']);
            }

            $account = AccountsPayable::query()->lockForUpdate()->findOrFail($request->accounts_payable_id);
            $this->assertOpenAccount($account);
            $this->assertAvailableBalance($account, (float) $request->amount_base, $request->id);

            $method = $request->method;
            $reference = $data['reference'] ?? $request->reference;

            if (($method === CashRegisterMovement::METHOD_TRANSFER || $method === 'transferencia') && ! $reference) {
                throw ValidationException::withMessages(['reference' => 'La transferencia requiere referencia antes de ejecutar.']);
            }

            $payment = app(AccountsPayableService::class)->registerPayment($account, $user, [
                'payment_currency' => $request->payment_currency,
                'amount' => (float) $request->amount,
                'exchange_rate_type_id' => $request->exchange_rate_type_id,
                'exchange_rate' => $request->exchange_rate,
                'method' => $method,
                'reference' => $reference,
                'notes' => $data['notes'] ?? $request->notes,
                'cash_register_session_id' => $data['cash_register_session_id'] ?? $request->cash_register_session_id,
                'paid_at' => now(),
            ]);

            $request->fill([
                'status' => AccountsPayablePaymentRequest::STATUS_EXECUTED,
                'accounts_payable_payment_id' => $payment->id,
                'reference' => $reference,
                'notes' => $data['notes'] ?? $request->notes,
                'cash_register_session_id' => $data['cash_register_session_id'] ?? $request->cash_register_session_id,
                'executed_by' => $user->id,
                'executed_at' => now(),
            ])->save();

            return $request->refresh()->load(['account.supplier', 'account.purchaseOrder', 'payment']);
        });
    }

    private function assertOpenAccount(AccountsPayable $account): void
    {
        if ((float) $account->balance_base_amount <= 0.0) {
            throw ValidationException::withMessages(['amount' => 'La cuenta por pagar ya esta pagada.']);
        }
    }

    private function assertAvailableBalance(AccountsPayable $account, float $amountBase, ?int $ignoreRequestId = null): void
    {
        $reserved = AccountsPayablePaymentRequest::query()
            ->where('accounts_payable_id', $account->id)
            ->whereIn('status', [AccountsPayablePaymentRequest::STATUS_PREPARED, AccountsPayablePaymentRequest::STATUS_APPROVED])
            ->when($ignoreRequestId, fn ($query) => $query->where('id', '!=', $ignoreRequestId))
            ->sum('amount_base');

        $available = round((float) $account->balance_base_amount - (float) $reserved, 4);

        if ($amountBase > $available) {
            throw ValidationException::withMessages(['amount' => 'El pago supera el saldo disponible despues de solicitudes pendientes.']);
        }
    }

    private function requiresApproval(User $user, float $amountBase, ?string $method): bool
    {
        $threshold = (float) config('app.accounts_payable.approval_threshold_base_amount', 100);
        $canApprove = $user->can('accounts_payable.payment_requests.approve');

        if (! $canApprove) {
            return true;
        }

        if ($method === CashRegisterMovement::METHOD_CASH && ! $canApprove) {
            return true;
        }

        return $amountBase > $threshold;
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
            throw ValidationException::withMessages(['exchange_rate_type_id' => 'El pago en bolivares requiere un tipo de tasa activo.']);
        }

        $rate = $exchangeRate ?: $this->activeRateFor($rateType)?->rate;

        if (! $rate || (float) $rate <= 0.0) {
            throw ValidationException::withMessages(['exchange_rate' => 'El pago en bolivares requiere una tasa activa mayor que cero.']);
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
}
