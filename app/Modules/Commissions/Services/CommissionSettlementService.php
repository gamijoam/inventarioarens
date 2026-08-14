<?php

namespace App\Modules\Commissions\Services;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionSettlement;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Sync\Services\SyncOutboxService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommissionSettlementService
{
    public function __construct(
        private readonly SyncOutboxService $syncOutbox,
        private readonly CommissionLedgerService $ledger,
    ) {}

    public function approve(array $entryIds, User $approver): Collection
    {
        return DB::transaction(function () use ($entryIds, $approver): Collection {
            $ids = collect($entryIds)->map(fn ($id) => (int) $id)->unique()->values();
            $entries = CommissionEntry::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($entries->count() !== $ids->count() || $entries->contains(fn ($entry) => $entry->status !== CommissionEntry::STATUS_AVAILABLE)) {
                throw ValidationException::withMessages(['entry_ids' => 'Todas las comisiones deben existir en esta empresa y estar disponibles.']);
            }

            $approvedAt = now();
            foreach ($entries as $entry) {
                $entry->update([
                    'status' => CommissionEntry::STATUS_APPROVED,
                    'approved_by' => $approver->id,
                    'approved_at' => $approvedAt,
                ]);
            }

            $this->syncOutbox->record(
                eventType: 'commission_entries.approved',
                aggregateType: 'commission_entry',
                aggregateId: $entries->first()->id,
                payload: [
                    'entry_uuids' => $entries->pluck('entry_uuid')->all(),
                    'approved_by_email' => $approver->email,
                    'approved_at' => $approvedAt->toJSON(),
                    'updated_at' => $approvedAt->toJSON(),
                ],
                idempotencyKey: 'commission_entries.approved:'.hash('sha256', $entries->pluck('entry_uuid')->sort()->implode('|').':'.$approvedAt->getTimestamp())
            );

            return $entries->fresh()->load('beneficiary');
        });
    }

    public function settle(array $data, User $payer): CommissionSettlement
    {
        return DB::transaction(function () use ($data, $payer): CommissionSettlement {
            $ids = collect($data['entry_ids'])->map(fn ($id) => (int) $id)->unique()->values();
            $entries = CommissionEntry::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($entries->count() !== $ids->count() || $entries->contains(fn ($entry) => $entry->status !== CommissionEntry::STATUS_APPROVED)) {
                throw ValidationException::withMessages(['entry_ids' => 'Todas las comisiones deben existir en esta empresa, estar aprobadas y no haber sido pagadas.']);
            }
            if ($entries->pluck('beneficiary_user_id')->unique()->count() !== 1) {
                throw ValidationException::withMessages(['entry_ids' => 'Un pago solo puede agrupar comisiones de una misma persona.']);
            }

            $totalBase = round((float) $entries->sum('commission_base_amount'), 4);
            if ($totalBase <= 0) {
                throw ValidationException::withMessages(['entry_ids' => 'El total neto del pago debe ser mayor que cero.']);
            }

            [$rateType, $rate] = $this->paymentRate($data['payment_currency'], $data['exchange_rate_type_id'] ?? null);
            $totalLocal = $data['payment_currency'] === 'VES' ? round($totalBase * (float) $rate->rate, 4) : 0.0;
            $settlement = CommissionSettlement::create([
                'settlement_uuid' => (string) Str::uuid(),
                'beneficiary_user_id' => $entries->first()->beneficiary_user_id,
                'status' => CommissionSettlement::STATUS_PAID,
                'payment_currency' => $data['payment_currency'],
                'total_base_amount' => $totalBase,
                'total_local_amount' => $totalLocal,
                'payment_amount' => $data['payment_currency'] === 'VES' ? $totalLocal : $totalBase,
                'exchange_rate_type_id' => $rateType?->id,
                'exchange_rate_type_code' => $rateType?->code,
                'exchange_rate' => $rate?->rate,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_by' => $payer->id,
                'paid_at' => now(),
            ]);

            foreach ($entries as $entry) {
                $settlement->items()->create([
                    'commission_entry_id' => $entry->id,
                    'commission_base_amount' => $entry->commission_base_amount,
                ]);
                $entry->update(['status' => CommissionEntry::STATUS_PAID]);
            }

            $this->recordSettlementSync($settlement, $entries, $payer);

            return $this->load($settlement);
        });
    }

    public function adjust(array $data, User $creator): CommissionEntry
    {
        return DB::transaction(function () use ($data, $creator): CommissionEntry {
            $entry = CommissionEntry::create([
                'entry_uuid' => (string) Str::uuid(),
                'beneficiary_user_id' => $data['beneficiary_user_id'],
                'beneficiary_role' => $data['beneficiary_role'] ?? 'seller',
                'entry_type' => CommissionEntry::TYPE_ADJUSTMENT,
                'plan_name_snapshot' => 'Ajuste manual',
                'percentage_snapshot' => 0,
                'sale_currency' => 'USD',
                'source_amount' => $data['amount_base'],
                'eligible_base_amount' => $data['amount_base'],
                'commission_base_amount' => $data['amount_base'],
                'status' => CommissionEntry::STATUS_AVAILABLE,
                'adjustment_reason' => $data['reason'],
                'created_by' => $creator->id,
                'earned_at' => now(),
                'available_at' => now(),
            ]);
            $this->ledger->recordSyncEvent($entry);

            return $entry->refresh()->load('beneficiary');
        });
    }

    private function paymentRate(string $currency, ?int $rateTypeId): array
    {
        if ($currency === 'USD') {
            return [null, null];
        }

        $rateType = ExchangeRateType::query()->where('is_active', true)->find($rateTypeId);
        $rate = $rateType?->rates()
            ->where('base_currency', ExchangeRate::BASE_USD)
            ->where('quote_currency', ExchangeRate::QUOTE_VES)
            ->where('is_active', true)
            ->latest('effective_at')
            ->first();
        if (! $rateType || ! $rate || (float) $rate->rate <= 0) {
            throw ValidationException::withMessages(['exchange_rate_type_id' => 'Selecciona un tipo de tasa activo con una tasa vigente.']);
        }

        return [$rateType, $rate];
    }

    private function recordSettlementSync(CommissionSettlement $settlement, Collection $entries, User $payer): void
    {
        $settlement->loadMissing('beneficiary');
        $this->syncOutbox->record(
            eventType: 'commission_settlement.created',
            aggregateType: 'commission_settlement',
            aggregateId: $settlement->id,
            payload: array_merge($settlement->only([
                'settlement_uuid', 'status', 'payment_currency', 'total_base_amount',
                'total_local_amount', 'payment_amount', 'exchange_rate_type_code',
                'exchange_rate', 'reference', 'notes', 'paid_at', 'created_at', 'updated_at',
            ]), [
                'beneficiary_email' => $settlement->beneficiary?->email,
                'paid_by_email' => $payer->email,
                'entry_uuids' => $entries->pluck('entry_uuid')->all(),
            ]),
            idempotencyKey: "commission_settlement.created:{$settlement->settlement_uuid}"
        );
    }

    private function load(CommissionSettlement $settlement): CommissionSettlement
    {
        return $settlement->refresh()->load(['beneficiary', 'items.entry']);
    }
}
