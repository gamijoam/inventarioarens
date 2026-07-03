<?php

namespace App\Modules\FinancialAdjustments\Services;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\Products\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialAdjustmentService
{
    public function create(User $user, array $data): FinancialAdjustment
    {
        return DB::transaction(function () use ($user, $data): FinancialAdjustment {
            [$rateType, $exchangeRate, $amountBase, $amountLocal] = $this->amounts(
                $data['currency'],
                (float) $data['amount'],
                $data['exchange_rate_type_id'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            );

            if ($data['account_type'] === FinancialAdjustment::ACCOUNT_RECEIVABLE) {
                return $this->applyToReceivable($user, $data, $rateType, $exchangeRate, $amountBase, $amountLocal);
            }

            return $this->applyToPayable($user, $data, $rateType, $exchangeRate, $amountBase, $amountLocal);
        });
    }

    private function applyToReceivable(
        User $user,
        array $data,
        ?ExchangeRateType $rateType,
        ?float $exchangeRate,
        float $amountBase,
        float $amountLocal,
    ): FinancialAdjustment {
        $account = AccountsReceivable::query()
            ->lockForUpdate()
            ->findOrFail($data['account_id']);

        $this->assertAdjustable($account->balance_base_amount, $amountBase, 'El ajuste supera el saldo pendiente por cobrar.');

        $adjustment = $this->createAdjustment($user, $data, $rateType, $exchangeRate, $amountBase, $amountLocal, [
            'accounts_receivable_id' => $account->id,
        ]);

        $account->adjusted_base_amount = round((float) $account->adjusted_base_amount + $amountBase, 4);
        $account->adjusted_local_amount = round((float) $account->adjusted_local_amount + $amountLocal, 4);
        $this->recalculateReceivable($account);
        $account->save();

        return $adjustment->refresh()->load('accountsReceivable');
    }

    private function applyToPayable(
        User $user,
        array $data,
        ?ExchangeRateType $rateType,
        ?float $exchangeRate,
        float $amountBase,
        float $amountLocal,
    ): FinancialAdjustment {
        $account = AccountsPayable::query()
            ->lockForUpdate()
            ->findOrFail($data['account_id']);

        $this->assertAdjustable($account->balance_base_amount, $amountBase, 'El ajuste supera el saldo pendiente por pagar.');

        $adjustment = $this->createAdjustment($user, $data, $rateType, $exchangeRate, $amountBase, $amountLocal, [
            'accounts_payable_id' => $account->id,
        ]);

        $account->adjusted_base_amount = round((float) $account->adjusted_base_amount + $amountBase, 4);
        $account->adjusted_local_amount = round((float) $account->adjusted_local_amount + $amountLocal, 4);
        $this->recalculatePayable($account);
        $account->save();

        return $adjustment->refresh()->load('accountsPayable');
    }

    private function createAdjustment(
        User $user,
        array $data,
        ?ExchangeRateType $rateType,
        ?float $exchangeRate,
        float $amountBase,
        float $amountLocal,
        array $accountLink,
    ): FinancialAdjustment {
        $lastSequence = FinancialAdjustment::query()
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->value('sequence');
        $sequence = ((int) $lastSequence) + 1;

        return FinancialAdjustment::create($accountLink + [
            'sequence' => $sequence,
            'document_number' => 'AJF-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'account_type' => $data['account_type'],
            'status' => FinancialAdjustment::STATUS_APPLIED,
            'currency' => $data['currency'],
            'amount' => $data['amount'],
            'exchange_rate_type_id' => $rateType?->id,
            'exchange_rate_type_code' => $rateType?->code,
            'exchange_rate' => $exchangeRate,
            'amount_base' => $amountBase,
            'amount_local' => $amountLocal,
            'reason' => $data['reason'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
            'applied_at' => $data['applied_at'] ?? now(),
        ]);
    }

    private function amounts(string $currency, float $amount, ?int $rateTypeId, ?float $exchangeRate): array
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
                'exchange_rate_type_id' => 'El ajuste en bolivares requiere un tipo de tasa activo.',
            ]);
        }

        $rate = $exchangeRate ?: $this->activeRateFor($rateType)?->rate;

        if (! $rate || (float) $rate <= 0.0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'El ajuste en bolivares requiere una tasa activa mayor que cero.',
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

    private function assertAdjustable(string|float $balanceBase, float $amountBase, string $message): void
    {
        if ((float) $balanceBase <= 0.0) {
            throw ValidationException::withMessages(['amount' => 'La cuenta ya no tiene saldo pendiente.']);
        }

        if ($amountBase > round((float) $balanceBase, 4)) {
            throw ValidationException::withMessages(['amount' => $message]);
        }
    }

    private function recalculateReceivable(AccountsReceivable $account): void
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

        $this->setStatus($account, 'collected_base_amount');
    }

    private function recalculatePayable(AccountsPayable $account): void
    {
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

        $this->setStatus($account, 'paid_base_amount');
    }

    private function setStatus(AccountsReceivable|AccountsPayable $account, string $paidColumn): void
    {
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

        $account->status = ((float) $account->{$paidColumn} > 0.0 || (float) $account->adjusted_base_amount > 0.0)
            ? AccountsReceivable::STATUS_PARTIAL
            : AccountsReceivable::STATUS_PENDING;
    }
}
