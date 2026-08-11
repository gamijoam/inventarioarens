<?php

namespace App\Modules\CashRegister\Services;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
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

            $openingMovements = $this->openingMovements($data);
            $openingBaseAmount = 0.0;
            $openingLocalAmount = 0.0;
            $openingCashUsd = 0.0;
            $openingCashVes = 0.0;
            $resolvedOpeningMovements = [];

            foreach ($openingMovements as $movement) {
                if ((float) $movement['amount'] <= 0) {
                    continue;
                }

                $resolved = $this->resolveAmount($movement);
                $movement['resolved'] = $resolved;
                $openingBaseAmount += (float) $resolved['amount_base'];
                $openingLocalAmount += (float) ($resolved['amount_local'] ?? 0);
                if (($movement['method'] ?? CashRegisterMovement::METHOD_CASH) !== CashRegisterMovement::METHOD_CASH) {
                    $resolvedOpeningMovements[] = $movement;

                    continue;
                }

                if (strtoupper($movement['currency']) === Product::CURRENCY_USD) {
                    $openingCashUsd += (float) $movement['amount'];
                } else {
                    $openingCashVes += (float) $movement['amount'];
                }
                $resolvedOpeningMovements[] = $movement;
            }

            $session = CashRegisterSession::create([
                'branch_id' => $branch->id,
                'cash_register_id' => $physicalRegister?->id,
                'cashier_id' => $cashier->id,
                'opened_by' => $operator->id,
                'status' => CashRegisterSession::STATUS_OPEN,
                'opening_base_amount' => round($openingBaseAmount, 4),
                'opening_local_amount' => round($openingLocalAmount, 4),
                'expected_base_amount' => round($openingBaseAmount, 4),
                'expected_local_amount' => round($openingLocalAmount, 4),
                'expected_cash_usd' => round($openingCashUsd, 4),
                'expected_cash_ves' => round($openingCashVes, 4),
                'opened_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($resolvedOpeningMovements as $movement) {
                if ((float) $movement['amount'] <= 0) {
                    continue;
                }

                $this->createMovement($session, CashRegisterMovement::TYPE_OPENING, CashRegisterMovement::METHOD_CASH, [
                    'currency' => $movement['currency'],
                    'amount' => $movement['amount'],
                    'exchange_rate_type_id' => $movement['exchange_rate_type_id'] ?? null,
                    'notes' => $movement['notes'],
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
            $this->assertOperatorCanOperate($session, $operator);

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
            $this->assertOperatorCanClose($session, $operator);
            $this->recalculateExpectedTotals($session);

            $counted = $this->closingAmount($data);
            $countedPhysical = $this->closingPhysicalAmounts($data);
            $counts = $this->cashCounts($data);
            $differenceCashUsd = round($countedPhysical['USD'] - (float) ($session->expected_cash_usd ?? $session->opening_base_amount), 4);
            $differenceCashVes = round($countedPhysical['VES'] - (float) ($session->expected_cash_ves ?? $session->opening_local_amount), 4);

            if ((abs($differenceCashUsd) >= 0.01 || abs($differenceCashVes) >= 0.01)
                && mb_strlen(trim((string) ($data['closing_notes'] ?? ''))) < 3) {
                throw ValidationException::withMessages([
                    'closing_notes' => 'Debes justificar la diferencia de efectivo USD/VES antes de cerrar.',
                ]);
            }

            $session->update([
                'status' => CashRegisterSession::STATUS_CLOSED,
                'closed_by' => $operator->id,
                'counted_base_amount' => $counted['amount_base'],
                'counted_local_amount' => $counted['amount_local'] ?? 0,
                'counted_cash_usd' => $countedPhysical['USD'],
                'counted_cash_ves' => $countedPhysical['VES'],
                'difference_base_amount' => round($counted['amount_base'] - (float) $session->expected_base_amount, 4),
                'difference_local_amount' => round(($counted['amount_local'] ?? 0) - (float) $session->expected_local_amount, 4),
                'difference_cash_usd' => $differenceCashUsd,
                'difference_cash_ves' => $differenceCashVes,
                'closed_at' => now(),
                'closing_notes' => $data['closing_notes'] ?? null,
                'counting_mode' => $data['counting_mode'] ?? CashRegisterSession::COUNTING_STANDARD,
            ]);

            if ($counts !== []) {
                $session->counts()->delete();
                foreach ($counts as $count) {
                    $session->counts()->create($count);
                }
            }

            $this->recordSessionSyncEvent($session->refresh(), 'cash.session.closed');

            return $session->refresh()->load(['branch', 'cashRegister', 'movements', 'counts']);
        });
    }

    public function review(CashRegisterSession $session, array $data, User $operator): CashRegisterSession
    {
        return DB::transaction(function () use ($session, $data, $operator): CashRegisterSession {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($session->status !== CashRegisterSession::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se pueden revisar turnos cerrados.',
                ]);
            }

            $session->update([
                'review_status' => $data['status'],
                'reviewed_by' => $operator->id,
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? null,
            ]);

            return $session->refresh()->load(['branch', 'cashRegister', 'cashier', 'closer', 'reviewer', 'movements', 'counts']);
        });
    }

    public function recordPosPayment(CashRegisterSession $session, PosPayment $payment, User $operator): CashRegisterSession
    {
        return DB::transaction(function () use ($session, $payment, $operator): CashRegisterSession {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertOpen($session);
            $this->assertOperatorCanOperate($session, $operator);

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

            $this->recalculateExpectedTotals($session);

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

            $this->recalculateExpectedTotals($session);

            return $session->refresh();
        });
    }

    public function recordPayablePayment(CashRegisterSession $session, AccountsPayablePayment $payment, User $operator): CashRegisterSession
    {
        return DB::transaction(function () use ($session, $payment, $operator): CashRegisterSession {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertOpen($session);

            if ((int) $session->cashier_id !== (int) $operator->id) {
                throw ValidationException::withMessages([
                    'cash_register_session_id' => 'Solo puedes registrar pagos en tu caja abierta.',
                ]);
            }

            CashRegisterMovement::create([
                'cash_register_session_id' => $session->id,
                'type' => CashRegisterMovement::TYPE_OUTFLOW,
                'method' => $payment->method ?? CashRegisterMovement::METHOD_OTHER,
                'currency' => $payment->payment_currency,
                'amount' => $payment->amount,
                'amount_base' => $payment->amount_base,
                'amount_local' => $payment->amount_local,
                'exchange_rate_type_id' => $payment->exchange_rate_type_id,
                'exchange_rate_type_code' => $payment->exchange_rate_type_code,
                'exchange_rate' => $payment->exchange_rate,
                'source_type' => AccountsPayablePayment::class,
                'source_id' => $payment->id,
                'reference' => $payment->reference,
                'notes' => "Pago CxP #{$payment->id}",
                'created_by' => $operator->id,
            ]);

            $this->recalculateExpectedTotals($session);

            return $session->refresh();
        });
    }

    public function recordWarrantyRefund(CashRegisterSession $session, array $data, User $operator): CashRegisterMovement
    {
        return DB::transaction(function () use ($session, $data, $operator): CashRegisterMovement {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertOpen($session);
            $this->assertOperatorCanOperate($session, $operator);

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

    private function openingMovements(array $data): array
    {
        if (array_key_exists('opening_base_amount', $data) || array_key_exists('opening_local_amount', $data)) {
            return [
                [
                    'currency' => Product::CURRENCY_USD,
                    'amount' => (float) ($data['opening_base_amount'] ?? 0),
                    'notes' => 'Fondo inicial USD.',
                ],
                [
                    'currency' => Product::CURRENCY_VES,
                    'amount' => (float) ($data['opening_local_amount'] ?? 0),
                    'exchange_rate_type_id' => $data['exchange_rate_type_id'] ?? null,
                    'notes' => 'Fondo inicial VES.',
                ],
            ];
        }

        return [[
            'currency' => $data['opening_currency'] ?? Product::CURRENCY_USD,
            'amount' => (float) ($data['opening_amount'] ?? 0),
            'exchange_rate_type_id' => $data['exchange_rate_type_id'] ?? null,
            'notes' => 'Monto inicial de caja.',
        ]];
    }

    private function closingAmount(array $data): array
    {
        if (! empty($data['counts'])) {
            $amounts = $this->cashCountTotals($data['counts']);
            $data['counted_base_amount'] = $amounts['USD'];
            $data['counted_local_amount'] = $amounts['VES'];
        }

        if (array_key_exists('counted_base_amount', $data) || array_key_exists('counted_local_amount', $data)) {
            $base = $this->resolveAmount([
                'currency' => Product::CURRENCY_USD,
                'amount' => (float) ($data['counted_base_amount'] ?? 0),
            ]);
            $localAmount = (float) ($data['counted_local_amount'] ?? 0);
            $local = $localAmount > 0
                ? $this->resolveAmount([
                    'currency' => Product::CURRENCY_VES,
                    'amount' => $localAmount,
                    'exchange_rate_type_id' => $data['exchange_rate_type_id'] ?? null,
                ])
                : ['amount_base' => 0, 'amount_local' => 0];

            return [
                'amount_base' => round((float) $base['amount_base'] + (float) $local['amount_base'], 4),
                'amount_local' => round((float) ($local['amount_local'] ?? 0), 4),
            ];
        }

        return $this->resolveAmount([
            'currency' => $data['counted_currency'] ?? Product::CURRENCY_USD,
            'amount' => $data['counted_amount'],
            'exchange_rate_type_id' => $data['exchange_rate_type_id'] ?? null,
        ]);
    }

    private function cashCounts(array $data): array
    {
        if (empty($data['counts'])) {
            return [];
        }

        return collect($data['counts'])
            ->map(fn (array $count): array => [
                'currency' => strtoupper($count['currency']),
                'denomination' => round((float) $count['denomination'], 4),
                'quantity' => (int) $count['quantity'],
                'total_amount' => round((float) $count['denomination'] * (int) $count['quantity'], 4),
            ])
            ->filter(fn (array $count): bool => $count['quantity'] > 0)
            ->values()
            ->all();
    }

    private function cashCountTotals(array $counts): array
    {
        return collect($counts)
            ->groupBy(fn (array $count): string => strtoupper($count['currency']))
            ->map(fn ($group): float => round((float) $group->sum(fn (array $count): float => (float) $count['denomination'] * (int) $count['quantity']), 4))
            ->all() + ['USD' => 0.0, 'VES' => 0.0];
    }

    private function recalculateExpectedTotals(CashRegisterSession $session): void
    {
        $movements = CashRegisterMovement::query()
            ->where('cash_register_session_id', $session->id)
            ->get();

        $base = 0.0;
        $local = 0.0;
        $cashUsd = 0.0;
        $cashVes = 0.0;

        foreach ($movements as $movement) {
            $sign = $movement->type === CashRegisterMovement::TYPE_OUTFLOW ? -1 : 1;
            $base += $sign * (float) $movement->amount_base;
            $local += $sign * (float) ($movement->amount_local ?? 0);

            if ($movement->method === CashRegisterMovement::METHOD_CASH) {
                if ($movement->currency === Product::CURRENCY_USD) {
                    $cashUsd += $sign * (float) $movement->amount;
                } else {
                    $cashVes += $sign * (float) $movement->amount;
                }
            }
        }

        $session->update([
            'expected_base_amount' => round($base, 4),
            'expected_local_amount' => round($local, 4),
            'expected_cash_usd' => round($cashUsd, 4),
            'expected_cash_ves' => round($cashVes, 4),
        ]);
    }

    private function closingPhysicalAmounts(array $data): array
    {
        if (! empty($data['counts'])) {
            return $this->cashCountTotals($data['counts']);
        }

        $legacyCurrency = strtoupper($data['counted_currency'] ?? Product::CURRENCY_USD);
        $legacyAmount = array_key_exists('counted_amount', $data)
            ? (float) $data['counted_amount']
            : 0.0;

        return [
            'USD' => round((float) ($data['counted_cash_usd'] ?? $data['counted_base_amount'] ?? ($legacyCurrency === Product::CURRENCY_USD ? $legacyAmount : 0)), 4),
            'VES' => round((float) ($data['counted_cash_ves'] ?? $data['counted_local_amount'] ?? ($legacyCurrency === Product::CURRENCY_VES ? $legacyAmount : 0)), 4),
        ];
    }

    private function assertOperatorCanOperate(CashRegisterSession $session, User $operator): void
    {
        if ((int) $session->cashier_id === (int) $operator->id) {
            return;
        }

        if (! $this->isCashSupervisor($operator)) {
            throw ValidationException::withMessages([
                'cash_register_session_id' => 'Solo el cajero o un supervisor autorizado puede mover dinero en este turno.',
            ]);
        }
    }

    private function assertOperatorCanClose(CashRegisterSession $session, User $operator): void
    {
        if ((int) $session->cashier_id === (int) $operator->id || $this->isCashSupervisor($operator)) {
            return;
        }

        throw ValidationException::withMessages([
            'cash_register_session_id' => 'Solo el cajero o un supervisor autorizado puede cerrar este turno.',
        ]);
    }

    private function isCashSupervisor(User $operator): bool
    {
        $tenant = app(TenantManager::class)->current();
        if ($tenant && function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant->id);
        }

        return $operator->hasPermissionTo('cash_register.close')
            && $operator->hasAnyRole(['Owner', 'Administrador', 'Administrador local', 'Gerente']);
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
        $session->loadMissing(['branch', 'cashRegister', 'cashier', 'opener', 'closer', 'reviewer']);

        $this->syncOutbox->record(
            eventType: $eventType,
            aggregateType: 'cash_register_session',
            aggregateId: $session->id,
            payload: [
                'session_id' => $session->id,
                'branch_id' => $session->branch_id,
                'branch_code' => $session->branch?->code,
                'cash_register_id' => $session->cash_register_id,
                'cash_register_code' => $session->cashRegister?->code,
                'cashier_id' => $session->cashier_id,
                'cashier_email' => $session->cashier?->email,
                'opened_by' => $session->opened_by,
                'opened_by_email' => $session->opener?->email,
                'closed_by' => $session->closed_by,
                'closed_by_email' => $session->closer?->email,
                'status' => $session->status,
                'opening_base_amount' => (string) $session->opening_base_amount,
                'opening_local_amount' => (string) $session->opening_local_amount,
                'expected_base_amount' => (string) $session->expected_base_amount,
                'expected_local_amount' => (string) $session->expected_local_amount,
                'expected_cash_usd' => $session->expected_cash_usd === null ? null : (string) $session->expected_cash_usd,
                'expected_cash_ves' => $session->expected_cash_ves === null ? null : (string) $session->expected_cash_ves,
                'counted_base_amount' => $session->counted_base_amount === null ? null : (string) $session->counted_base_amount,
                'counted_local_amount' => $session->counted_local_amount === null ? null : (string) $session->counted_local_amount,
                'counted_cash_usd' => $session->counted_cash_usd === null ? null : (string) $session->counted_cash_usd,
                'counted_cash_ves' => $session->counted_cash_ves === null ? null : (string) $session->counted_cash_ves,
                'difference_base_amount' => $session->difference_base_amount === null ? null : (string) $session->difference_base_amount,
                'difference_local_amount' => $session->difference_local_amount === null ? null : (string) $session->difference_local_amount,
                'difference_cash_usd' => $session->difference_cash_usd === null ? null : (string) $session->difference_cash_usd,
                'difference_cash_ves' => $session->difference_cash_ves === null ? null : (string) $session->difference_cash_ves,
                'counting_mode' => $session->counting_mode,
                'review_status' => $session->review_status,
                'reviewed_by' => $session->reviewed_by,
                'reviewed_by_email' => $session->reviewer?->email,
                'reviewed_at' => $session->reviewed_at?->toJSON(),
                'review_notes' => $session->review_notes,
                'opened_at' => $session->opened_at?->toJSON(),
                'closed_at' => $session->closed_at?->toJSON(),
            ],
            idempotencyKey: "{$eventType}:cash_register_session:{$session->id}"
        );
    }
}
