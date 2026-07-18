<?php

namespace App\Modules\CashRegister\Services;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivablePayment;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncOutboxService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashRegisterService
{
    public function __construct(
        private readonly SyncOutboxService $syncOutbox,
    ) {}

    public function open(User $operator, Branch $branch, ?CashRegister $physicalRegister, ?User $cashier, array $data): CashRegisterSession
    {
        return DB::transaction(function () use ($operator, $branch, $physicalRegister, $cashier, $data): CashRegisterSession {
            $cashier ??= $operator;
            $tenant = app(TenantManager::class)->require();

            if (! $cashier->belongsToTenant($tenant)) {
                throw ValidationException::withMessages([
                    'cashier_id' => 'El cajero no pertenece a la empresa actual.',
                ]);
            }

            if ($physicalRegister) {
                $physicalRegister = CashRegister::query()->lockForUpdate()->findOrFail($physicalRegister->id);

                if ((int) $physicalRegister->tenant_id !== (int) $tenant->id) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'La caja fisica no pertenece a la empresa actual.',
                    ]);
                }

                if ((int) $physicalRegister->branch_id !== (int) $branch->id) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'La caja fisica pertenece a otra sucursal.',
                    ]);
                }

                if ($physicalRegister->status !== CashRegister::STATUS_ACTIVE) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'La caja fisica no esta activa.',
                    ]);
                }

                $physicalRegisterOpen = CashRegisterSession::query()
                    ->where('cash_register_id', $physicalRegister->id)
                    ->where('status', CashRegisterSession::STATUS_OPEN)
                    ->exists();

                if ($physicalRegisterOpen) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'La caja fisica ya esta abierta por otro turno.',
                    ]);
                }
            }

            $openSessionExists = CashRegisterSession::query()
                ->where('cashier_id', $cashier->id)
                ->where('status', CashRegisterSession::STATUS_OPEN)
                ->exists();

            if ($openSessionExists) {
                throw ValidationException::withMessages([
                    'cashier_id' => 'El cajero ya tiene una caja abierta.',
                ]);
            }

            $opening = $this->resolveAmount([
                'currency' => $data['opening_currency'] ?? Product::CURRENCY_USD,
                'amount' => $data['opening_amount'] ?? 0,
                'exchange_rate_type_id' => $data['exchange_rate_type_id'] ?? null,
            ]);

            $session = CashRegisterSession::create([
                'branch_id' => $branch->id,
                'cash_register_id' => $physicalRegister?->id,
                'cashier_id' => $cashier->id,
                'opened_by' => $operator->id,
                'status' => CashRegisterSession::STATUS_OPEN,
                'opening_base_amount' => $opening['amount_base'],
                'opening_local_amount' => $opening['amount_local'] ?? 0,
                'expected_base_amount' => $opening['amount_base'],
                'expected_local_amount' => $opening['amount_local'] ?? 0,
                'opened_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            if ((float) ($data['opening_amount'] ?? 0) > 0) {
                $this->createMovement($session, CashRegisterMovement::TYPE_OPENING, CashRegisterMovement::METHOD_CASH, [
                    'currency' => $data['opening_currency'] ?? Product::CURRENCY_USD,
                    'amount' => $data['opening_amount'],
                    'exchange_rate_type_id' => $data['exchange_rate_type_id'] ?? null,
                    'notes' => 'Monto inicial de caja.',
                ], $operator);
            }

            $this->recordSessionSyncEvent($session->refresh(), 'cash.session.opened');

            return $session->refresh()->load(['branch', 'cashRegister', 'movements']);
        });
    }

    public function addMovement(CashRegisterSession $session, array $data, User $operator): CashRegisterSession
    {
        return DB::transaction(function () use ($session, $data, $operator): CashRegisterSession {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertOpen($session);

            $this->createMovement($session, $data['type'], $data['method'], $data, $operator);
            $this->recalculateExpectedTotals($session);

            return $session->refresh()->load(['branch', 'cashRegister', 'movements']);
        });
    }

    public function close(CashRegisterSession $session, array $data, User $operator): CashRegisterSession
    {
        return DB::transaction(function () use ($session, $data, $operator): CashRegisterSession {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertOpen($session);
            $this->recalculateExpectedTotals($session);

            $counted = $this->resolveAmount([
                'currency' => $data['counted_currency'] ?? Product::CURRENCY_USD,
                'amount' => $data['counted_amount'],
                'exchange_rate_type_id' => $data['exchange_rate_type_id'] ?? null,
            ]);

            $session->update([
                'status' => CashRegisterSession::STATUS_CLOSED,
                'closed_by' => $operator->id,
                'counted_base_amount' => $counted['amount_base'],
                'counted_local_amount' => $counted['amount_local'] ?? 0,
                'difference_base_amount' => round($counted['amount_base'] - (float) $session->expected_base_amount, 4),
                'difference_local_amount' => round(($counted['amount_local'] ?? 0) - (float) $session->expected_local_amount, 4),
                'closed_at' => now(),
                'closing_notes' => $data['closing_notes'] ?? null,
            ]);

            $this->recordSessionSyncEvent($session->refresh(), 'cash.session.closed');

            return $session->refresh()->load(['branch', 'cashRegister', 'movements']);
        });
    }

    public function recordPosPayment(CashRegisterSession $session, PosPayment $payment, User $operator): CashRegisterSession
    {
        return DB::transaction(function () use ($session, $payment, $operator): CashRegisterSession {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertOpen($session);

            CashRegisterMovement::create([
                'cash_register_session_id' => $session->id,
                'type' => CashRegisterMovement::TYPE_POS_PAYMENT,
                'method' => $payment->method,
                'currency' => $payment->currency,
                'amount' => $payment->amount,
                'amount_base' => $payment->amount_base,
                'amount_local' => $payment->amount_local,
                'exchange_rate_type_id' => $payment->exchange_rate_type_id,
                'exchange_rate_type_code' => $payment->exchange_rate_type_code,
                'exchange_rate' => $payment->exchange_rate,
                'source_type' => PosPayment::class,
                'source_id' => $payment->id,
                'reference' => $payment->reference,
                'notes' => "Pago POS #{$payment->id}",
                'created_by' => $operator->id,
            ]);

            $session->update([
                'expected_base_amount' => round((float) $session->expected_base_amount + (float) $payment->amount_base, 4),
                'expected_local_amount' => round((float) $session->expected_local_amount + (float) ($payment->amount_local ?? 0), 4),
            ]);

            return $session->refresh();
        });
    }

    public function recordReceivablePayment(CashRegisterSession $session, AccountsReceivablePayment $payment, User $operator): CashRegisterSession
    {
        return DB::transaction(function () use ($session, $payment, $operator): CashRegisterSession {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertOpen($session);

            if ((int) $session->cashier_id !== (int) $operator->id) {
                throw ValidationException::withMessages([
                    'cash_register_session_id' => 'Solo puedes registrar cobros en tu caja abierta.',
                ]);
            }

            CashRegisterMovement::create([
                'cash_register_session_id' => $session->id,
                'type' => CashRegisterMovement::TYPE_INFLOW,
                'method' => $payment->method ?? CashRegisterMovement::METHOD_OTHER,
                'currency' => $payment->payment_currency,
                'amount' => $payment->amount,
                'amount_base' => $payment->amount_base,
                'amount_local' => $payment->amount_local,
                'exchange_rate_type_id' => $payment->exchange_rate_type_id,
                'exchange_rate_type_code' => $payment->exchange_rate_type_code,
                'exchange_rate' => $payment->exchange_rate,
                'source_type' => AccountsReceivablePayment::class,
                'source_id' => $payment->id,
                'reference' => $payment->reference,
                'notes' => "Cobro CxC #{$payment->id}",
                'created_by' => $operator->id,
            ]);

            $session->update([
                'expected_base_amount' => round((float) $session->expected_base_amount + (float) $payment->amount_base, 4),
                'expected_local_amount' => round((float) $session->expected_local_amount + (float) ($payment->amount_local ?? 0), 4),
            ]);

            return $session->refresh();
        });
    }

    public function recordWarrantyRefund(CashRegisterSession $session, array $data, User $operator): CashRegisterMovement
    {
        return DB::transaction(function () use ($session, $data, $operator): CashRegisterMovement {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertOpen($session);

            $movement = $this->createMovement($session, CashRegisterMovement::TYPE_OUTFLOW, $data['method'], [
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'exchange_rate_type_id' => $data['exchange_rate_type_id'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], $operator);
            $this->recalculateExpectedTotals($session);

            return $movement->refresh();
        });
    }

    public function previewAmount(array $data): array
    {
        return $this->resolveAmount($data);
    }

    private function createMovement(CashRegisterSession $session, string $type, ?string $method, array $data, ?User $operator): CashRegisterMovement
    {
        $resolved = $this->resolveAmount($data);

        return CashRegisterMovement::create([
            'cash_register_session_id' => $session->id,
            'type' => $type,
            'method' => $method,
            'currency' => strtoupper($data['currency']),
            'amount' => $data['amount'],
            'amount_base' => $resolved['amount_base'],
            'amount_local' => $resolved['amount_local'],
            'exchange_rate_type_id' => $resolved['exchange_rate_type_id'],
            'exchange_rate_type_code' => $resolved['exchange_rate_type_code'],
            'exchange_rate' => $resolved['exchange_rate'],
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $operator?->id,
        ]);
    }

    private function recalculateExpectedTotals(CashRegisterSession $session): void
    {
        $movements = CashRegisterMovement::query()
            ->where('cash_register_session_id', $session->id)
            ->get();

        $base = 0.0;
        $local = 0.0;

        foreach ($movements as $movement) {
            $sign = $movement->type === CashRegisterMovement::TYPE_OUTFLOW ? -1 : 1;
            $base += $sign * (float) $movement->amount_base;
            $local += $sign * (float) ($movement->amount_local ?? 0);
        }

        $session->update([
            'expected_base_amount' => round($base, 4),
            'expected_local_amount' => round($local, 4),
        ]);
    }

    private function resolveAmount(array $data): array
    {
        $currency = strtoupper($data['currency']);
        $amount = (float) $data['amount'];
        $rateType = null;
        $rate = null;

        if ($currency === Product::CURRENCY_VES || isset($data['exchange_rate_type_id'])) {
            $rateType = $this->rateTypeFor($data['exchange_rate_type_id'] ?? null);
            $rate = $this->activeRateFor($rateType);
        }

        $exchangeRate = $rate ? (float) $rate->rate : null;

        if ($currency === Product::CURRENCY_VES && ! $exchangeRate) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'El movimiento en bolivares requiere una tasa activa.',
            ]);
        }

        return [
            'amount_base' => $currency === Product::CURRENCY_USD ? round($amount, 4) : round($amount / $exchangeRate, 4),
            'amount_local' => $currency === Product::CURRENCY_VES ? round($amount, 4) : ($exchangeRate ? round($amount * $exchangeRate, 4) : null),
            'exchange_rate_type_id' => $rateType?->id,
            'exchange_rate_type_code' => $rateType?->code,
            'exchange_rate' => $exchangeRate,
        ];
    }

    private function rateTypeFor(?int $rateTypeId): ExchangeRateType
    {
        if ($rateTypeId) {
            return ExchangeRateType::query()->findOrFail($rateTypeId);
        }

        $rateType = ExchangeRateType::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if (! $rateType) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'No existe un tipo de tasa activo por defecto para caja.',
            ]);
        }

        return $rateType;
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

    private function assertOpen(CashRegisterSession $session): void
    {
        if ($session->status !== CashRegisterSession::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'status' => 'La caja no esta abierta.',
            ]);
        }
    }

    private function recordSessionSyncEvent(CashRegisterSession $session, string $eventType): void
    {
        $this->syncOutbox->record(
            eventType: $eventType,
            aggregateType: 'cash_register_session',
            aggregateId: $session->id,
            payload: [
                'session_id' => $session->id,
                'branch_id' => $session->branch_id,
                'cash_register_id' => $session->cash_register_id,
                'cashier_id' => $session->cashier_id,
                'opened_by' => $session->opened_by,
                'closed_by' => $session->closed_by,
                'status' => $session->status,
                'opening_base_amount' => (string) $session->opening_base_amount,
                'opening_local_amount' => (string) $session->opening_local_amount,
                'expected_base_amount' => (string) $session->expected_base_amount,
                'expected_local_amount' => (string) $session->expected_local_amount,
                'counted_base_amount' => $session->counted_base_amount === null ? null : (string) $session->counted_base_amount,
                'counted_local_amount' => $session->counted_local_amount === null ? null : (string) $session->counted_local_amount,
                'difference_base_amount' => $session->difference_base_amount === null ? null : (string) $session->difference_base_amount,
                'difference_local_amount' => $session->difference_local_amount === null ? null : (string) $session->difference_local_amount,
                'opened_at' => $session->opened_at?->toJSON(),
                'closed_at' => $session->closed_at?->toJSON(),
            ],
            idempotencyKey: "{$eventType}:cash_register_session:{$session->id}"
        );
    }
}
