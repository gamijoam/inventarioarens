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
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\SalesReversals\Models\SaleReversal;
use App\Modules\Sync\Services\SyncOutboxService;
use App\Support\Realtime\WsHub;
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

            if ((int) $cashier->id !== (int) $operator->id && ! $this->isCashSupervisor($operator)) {
                throw ValidationException::withMessages([
                    'cashier_id' => 'Solo un supervisor autorizado puede abrir un turno para otro cajero.',
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

            $this->recordSessionSyncEvent($session->refresh(), 'cash.session.opened');

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

            WsHub::publishTenant($session->tenant_id, 'cash_register.opened', [
                'session_id' => $session->id,
                'cashier_id' => $session->cashier_id,
                'cashier_name' => $cashier->name,
                'branch_id' => $branch->id,
                'cash_register_id' => $physicalRegister?->id,
                'opened_at' => $session->opened_at?->toIso8601String(),
            ]);

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
            $session->refresh();

            $countedPhysical = $this->closingPhysicalAmounts($data);
            $expectedCashUsd = (float) ($session->expected_cash_usd ?? $session->opening_base_amount);
            $expectedCashVes = (float) ($session->expected_cash_ves ?? $session->opening_local_amount);
            $differenceCashUsd = round($countedPhysical['USD'] - $expectedCashUsd, 4);
            $differenceCashVes = round($countedPhysical['VES'] - $expectedCashVes, 4);

            $this->assertOpenOrdersAcknowledged($session, $data);
            $this->assertZeroCountAcknowledged($countedPhysical, $expectedCashUsd, $expectedCashVes, $data);

            $closingRate = $this->closingRate($data, $countedPhysical['VES'] != 0.0 || $differenceCashVes != 0.0);
            $countedBase = round($countedPhysical['USD'] + ($closingRate ? $countedPhysical['VES'] / $closingRate : 0.0), 4);
            $differenceBase = round($differenceCashUsd + ($closingRate ? $differenceCashVes / $closingRate : 0.0), 4);
            $differenceLocal = round($differenceCashVes + ($closingRate ? $differenceCashUsd * $closingRate : 0.0), 4);

            if (($data['counting_mode'] ?? CashRegisterSession::COUNTING_STANDARD) !== CashRegisterSession::COUNTING_BLIND
                && (abs($differenceCashUsd) >= 0.01 || abs($differenceCashVes) >= 0.01)
                && mb_strlen(trim((string) ($data['closing_notes'] ?? ''))) < 3) {
                throw ValidationException::withMessages([
                    'closing_notes' => 'Debes justificar la diferencia de efectivo USD/VES antes de cerrar.',
                ]);
            }

            $session->update([
                'status' => CashRegisterSession::STATUS_CLOSED,
                'closed_by' => $operator->id,
                'counted_base_amount' => $countedBase,
                'counted_local_amount' => $countedPhysical['VES'],
                'counted_cash_usd' => $countedPhysical['USD'],
                'counted_cash_ves' => $countedPhysical['VES'],
                'difference_base_amount' => $differenceBase,
                'difference_local_amount' => $differenceLocal,
                'difference_cash_usd' => $differenceCashUsd,
                'difference_cash_ves' => $differenceCashVes,
                'closed_at' => now(),
                'closing_notes' => $data['closing_notes'] ?? null,
                'counting_mode' => $data['counting_mode'] ?? CashRegisterSession::COUNTING_STANDARD,
            ]);

            app(ReportZService::class)->assignZNumber($session);

            $this->recordSessionSyncEvent($session->refresh(), 'cash.session.closed');

            WsHub::publishTenant($session->tenant_id, 'cash_register.closed', [
                'session_id' => $session->id,
                'cashier_id' => $session->cashier_id,
                'closed_by' => $session->closed_by,
                'counted_base_amount' => (float) $session->counted_base_amount,
                'counted_local_amount' => (float) $session->counted_local_amount,
                'closed_at' => $session->closed_at?->toIso8601String(),
            ]);

            if (abs($differenceCashUsd) >= 0.01 || abs($differenceCashVes) >= 0.01) {
                WsHub::publishTenant($session->tenant_id, 'cash_register.discrepancy', [
                    'session_id' => $session->id,
                    'cash_register_name' => $session->cashRegister?->name,
                    'cashier_id' => $session->cashier_id,
                    'difference_cash_usd' => $differenceCashUsd,
                    'difference_cash_ves' => $differenceCashVes,
                    'counting_mode' => $session->counting_mode,
                    'timestamp' => now()->toIso8601String(),
                ]);
            }

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

            $hasCashDifference = abs((float) $session->difference_cash_usd) >= 0.01
                || abs((float) $session->difference_cash_ves) >= 0.01;
            if ($data['status'] === CashRegisterSession::REVIEW_APPROVED
                && $hasCashDifference
                && mb_strlen(trim((string) ($data['review_notes'] ?? ''))) < 3) {
                throw ValidationException::withMessages([
                    'review_notes' => 'Para aprobar un turno con diferencia de efectivo debes indicar cómo se resolvió (por ejemplo: descontado al cajero, error de conteo, depósito pendiente).',
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

            $this->persistMovement([
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

            $this->persistMovement([
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

            $this->persistMovement([
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

    public function recordSaleReversal(
        CashRegisterSession $session,
        PosPayment $payment,
        SaleReversal $reversal,
        User $operator,
        ?string $reference = null,
    ): CashRegisterMovement {
        return DB::transaction(function () use ($session, $payment, $reversal, $operator, $reference): CashRegisterMovement {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertOpen($session);
            $this->assertOperatorCanOperate($session, $operator);

            $movement = $this->persistMovement([
                'cash_register_session_id' => $session->id,
                'type' => CashRegisterMovement::TYPE_OUTFLOW,
                'method' => $payment->method,
                'currency' => $payment->currency,
                'amount' => $payment->amount,
                'amount_base' => $payment->amount_base,
                'amount_local' => $payment->amount_local,
                'exchange_rate_type_id' => $payment->exchange_rate_type_id,
                'exchange_rate_type_code' => $payment->exchange_rate_type_code,
                'exchange_rate' => $payment->exchange_rate,
                'source_type' => 'sale_reversal',
                'source_id' => $reversal->id,
                'reference' => $reference ?: "REV-{$reversal->id}",
                'notes' => "Reembolso por reversión de venta POS #{$payment->pos_order_id}",
                'created_by' => $operator->id,
            ]);
            $this->recalculateExpectedTotals($session);

            return $movement->refresh();
        });
    }

    /**
     * Reembolso de un cobro hecho por cuentas por cobrar cuando la venta se
     * reversa. Conserva la moneda, el metodo y la tasa del cobro original.
     */
    public function recordReceivablePaymentReversal(
        CashRegisterSession $session,
        AccountsReceivablePayment $payment,
        SaleReversal $reversal,
        User $operator,
        ?string $reference = null,
    ): CashRegisterMovement {
        return DB::transaction(function () use ($session, $payment, $reversal, $operator, $reference): CashRegisterMovement {
            $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertOpen($session);
            $this->assertOperatorCanOperate($session, $operator);

            $movement = $this->persistMovement([
                'cash_register_session_id' => $session->id,
                'type' => CashRegisterMovement::TYPE_OUTFLOW,
                'method' => $payment->method ?: CashRegisterMovement::METHOD_OTHER,
                'currency' => $payment->payment_currency,
                'amount' => $payment->amount,
                'amount_base' => $payment->amount_base,
                'amount_local' => $payment->amount_local,
                'exchange_rate_type_id' => $payment->exchange_rate_type_id,
                'exchange_rate_type_code' => $payment->exchange_rate_type_code,
                'exchange_rate' => $payment->exchange_rate,
                'source_type' => 'sale_reversal',
                'source_id' => $reversal->id,
                'reference' => $reference ?: "REV-{$reversal->id}",
                'notes' => "Reembolso de cobro CxC #{$payment->id} por reversion de venta #{$reversal->sale_id}",
                'created_by' => $operator->id,
            ]);
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

        return $this->persistMovement([
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

    private function persistMovement(array $attributes): CashRegisterMovement
    {
        $movement = CashRegisterMovement::create($attributes);
        $this->recordMovementSyncEvent($movement);

        return $movement;
    }

    /**
     * Las ordenes pendientes con abonos ya cobrados dejan dinero en esta caja
     * sin venta confirmada: el cierre debe hacerse a conciencia.
     */
    private function assertOpenOrdersAcknowledged(CashRegisterSession $session, array $data): void
    {
        if (! empty($data['confirm_open_orders'])) {
            return;
        }

        $openOrders = PosOrder::query()
            ->where('cash_register_session_id', $session->id)
            ->where('status', PosOrder::STATUS_OPEN)
            ->whereHas('payments', fn ($query) => $query->where('status', PosPayment::STATUS_CAPTURED))
            ->count();

        if ($openOrders > 0) {
            throw ValidationException::withMessages([
                'confirm_open_orders' => $openOrders === 1
                    ? 'Hay 1 orden pendiente con abonos cobrados en este turno. Complétala antes de cerrar o confirma el cierre: el abono queda en este turno y el saldo se cobrará en otro.'
                    : "Hay {$openOrders} órdenes pendientes con abonos cobrados en este turno. Complétalas antes de cerrar o confirma el cierre: los abonos quedan en este turno y los saldos se cobrarán en otro.",
            ]);
        }
    }

    private function assertZeroCountAcknowledged(array $countedPhysical, float $expectedCashUsd, float $expectedCashVes, array $data): void
    {
        if (! empty($data['confirm_zero_count'])) {
            return;
        }

        $countedNothing = abs($countedPhysical['USD']) < 0.0001 && abs($countedPhysical['VES']) < 0.0001;
        $expectsCash = $expectedCashUsd >= 0.01 || $expectedCashVes >= 0.01;

        if ($countedNothing && $expectsCash) {
            throw ValidationException::withMessages([
                'confirm_zero_count' => 'Registraste cero efectivo en USD y en Bs. Verifica el conteo de la gaveta; si realmente está vacía, confirma el cierre.',
            ]);
        }
    }

    /**
     * Tasa del momento del cierre: valora en USD solo la diferencia en Bs (el
     * dinero que falta o sobra hoy), sin revaluar los cobros historicos.
     */
    private function closingRate(array $data, bool $required): ?float
    {
        $rateTypeId = isset($data['exchange_rate_type_id']) ? (int) $data['exchange_rate_type_id'] : null;
        $rateType = $rateTypeId
            ? ExchangeRateType::query()->find($rateTypeId)
            : ExchangeRateType::query()->where('is_default', true)->where('is_active', true)->first();
        $rate = $rateType ? $this->activeRateFor($rateType) : null;
        $value = $rate ? (float) $rate->rate : null;

        if ($required && ! $value) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'El cierre con efectivo en bolívares requiere una tasa activa.',
            ]);
        }

        return $value ?: null;
    }

    private function recordMovementSyncEvent(CashRegisterMovement $movement): void
    {
        $movement->loadMissing('creator');

        $this->syncOutbox->record(
            eventType: 'cash.movement.recorded',
            aggregateType: 'cash_register_movement',
            aggregateId: $movement->id,
            payload: [
                'movement_id' => $movement->id,
                'session_id' => (int) $movement->cash_register_session_id,
                'type' => $movement->type,
                'method' => $movement->method,
                'currency' => $movement->currency,
                'amount' => (string) $movement->amount,
                'amount_base' => (string) $movement->amount_base,
                'amount_local' => $movement->amount_local === null ? null : (string) $movement->amount_local,
                'exchange_rate_type_code' => $movement->exchange_rate_type_code,
                'exchange_rate' => $movement->exchange_rate === null ? null : (string) $movement->exchange_rate,
                'source_type' => $movement->source_type,
                'reference' => $movement->reference,
                'notes' => $movement->notes,
                'created_by_email' => $movement->creator?->email,
                'created_at' => $movement->created_at?->toJSON(),
            ],
            idempotencyKey: "cash.movement.recorded:cash_register_movement:{$movement->id}"
        );
    }

    private function closingPhysicalAmounts(array $data): array
    {
        $legacyCurrency = strtoupper($data['counted_currency'] ?? Product::CURRENCY_USD);
        $legacyAmount = array_key_exists('counted_amount', $data)
            ? (float) $data['counted_amount']
            : 0.0;

        $usd = $data['counted_cash_usd'] ?? $data['counted_base_amount'] ?? null;
        $ves = $data['counted_cash_ves'] ?? $data['counted_local_amount'] ?? null;

        return [
            'USD' => round((float) ($usd ?? ($legacyCurrency === Product::CURRENCY_USD ? $legacyAmount : 0)), 4),
            'VES' => round((float) ($ves ?? ($legacyCurrency === Product::CURRENCY_VES ? $legacyAmount : 0)), 4),
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
