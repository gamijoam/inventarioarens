<?php

namespace App\Modules\Commissions\Services;

use App\Modules\Commissions\Models\CommissionPlan;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Sync\Services\SyncOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionPlanService
{
    public function __construct(private readonly SyncOutboxService $syncOutbox) {}

    public function create(array $data): CommissionPlan
    {
        return DB::transaction(function () use ($data): CommissionPlan {
            $userIds = $data['user_ids'];
            unset($data['user_ids']);
            $plan = CommissionPlan::create($data);
            $this->replaceAssignments($plan, $userIds);
            $this->recordSyncEvent($plan, 'commission_plan.created');

            return $this->load($plan);
        });
    }

    public function update(CommissionPlan $plan, array $data): CommissionPlan
    {
        return DB::transaction(function () use ($plan, $data): CommissionPlan {
            $userIds = $data['user_ids'] ?? null;
            unset($data['user_ids']);
            $plan->update($data);
            if ($userIds !== null) {
                $this->replaceAssignments($plan, $userIds);
            }
            $this->recordSyncEvent($plan, 'commission_plan.updated');

            return $this->load($plan);
        });
    }

    public function deactivate(CommissionPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $plan->update(['is_active' => false]);
            $plan->assignments()->update(['is_active' => false]);
            $this->recordSyncEvent($plan, 'commission_plan.updated');
        });
    }

    public function simulate(float $amount, string $currency, float $percentage, ?int $rateTypeId): array
    {
        $rateType = null;
        $rate = null;

        if ($currency === 'VES') {
            $rateType = ExchangeRateType::query()->findOrFail($rateTypeId);
            $rate = ExchangeRate::query()
                ->where('exchange_rate_type_id', $rateType->id)
                ->where('base_currency', ExchangeRate::BASE_USD)
                ->where('quote_currency', ExchangeRate::QUOTE_VES)
                ->where('is_active', true)
                ->latest('effective_at')
                ->first();

            if (! $rate || (float) $rate->rate <= 0) {
                throw ValidationException::withMessages([
                    'exchange_rate_type_id' => 'El tipo de tasa seleccionado no tiene una tasa activa.',
                ]);
            }
        }

        $eligibleBase = $currency === 'USD' ? $amount : $amount / (float) $rate->rate;

        return [
            'currency' => $currency,
            'input_amount' => number_format($amount, 4, '.', ''),
            'percentage' => number_format($percentage, 4, '.', ''),
            'exchange_rate_type_id' => $rateType?->id,
            'exchange_rate_type_code' => $rateType?->code,
            'exchange_rate' => $rate ? number_format((float) $rate->rate, 6, '.', '') : null,
            'rate_effective_at' => $rate?->effective_at?->toJSON(),
            'eligible_base_amount' => number_format(round($eligibleBase, 4), 4, '.', ''),
            'commission_base_amount' => number_format(round($eligibleBase * $percentage / 100, 4), 4, '.', ''),
        ];
    }

    private function replaceAssignments(CommissionPlan $plan, array $userIds): void
    {
        $plan->assignments()->delete();
        foreach ($userIds as $userId) {
            $plan->assignments()->create(['user_id' => $userId, 'is_active' => true]);
        }
    }

    private function load(CommissionPlan $plan): CommissionPlan
    {
        return $plan->refresh()->load(['exchangeRateType', 'assignments.user']);
    }

    private function recordSyncEvent(CommissionPlan $plan, string $eventType): void
    {
        $plan = $this->load($plan);
        $this->syncOutbox->record(
            eventType: $eventType,
            aggregateType: 'commission_plan',
            aggregateId: $plan->id,
            payload: [
                'id' => $plan->id,
                'name' => $plan->name,
                'beneficiary_role' => $plan->beneficiary_role,
                'percentage' => (string) $plan->percentage,
                'conversion_policy' => $plan->conversion_policy,
                'exchange_rate_type_code' => $plan->exchangeRateType?->code,
                'credit_policy' => $plan->credit_policy,
                'maturation_days' => $plan->maturation_days,
                'allow_self_stacking' => $plan->allow_self_stacking,
                'is_active' => $plan->is_active,
                'starts_at' => $plan->starts_at?->toJSON(),
                'ends_at' => $plan->ends_at?->toJSON(),
                'assignments' => $plan->assignments->map(fn ($assignment) => [
                    'user_email' => $assignment->user?->email,
                    'is_active' => $assignment->is_active,
                ])->all(),
                'created_at' => $plan->created_at?->toJSON(),
                'updated_at' => $plan->updated_at?->toJSON(),
            ],
            idempotencyKey: "{$eventType}:{$plan->id}:{$plan->updated_at?->getTimestamp()}"
        );
    }
}
