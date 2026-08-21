<?php

namespace App\Modules\Commissions\Services;

use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Models\AccountsReceivablePayment;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionPlan;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Products\Models\Product;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\Sync\Services\SyncOutboxService;
use App\Modules\Workshop\Models\ServiceOrder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommissionLedgerService
{
    /**
     * Cache del scope de cada promocion por pedido/request para evitar N+1.
     *
     * @var array<int, string|null>
     */
    private array $promotionScopeCache = [];

    public function __construct(private readonly SyncOutboxService $syncOutbox) {}

    public function recordPaidOrder(PosOrder $order): void
    {
        $order->loadMissing('sale.items');
        $earnedAt = $order->paid_at ?? now();
        $beneficiaries = [
            CommissionPlan::ROLE_SELLER => $order->seller_id,
            CommissionPlan::ROLE_CASHIER => $order->cashier_id,
        ];

        foreach ($beneficiaries as $role => $userId) {
            if (! $userId) {
                continue;
            }

            $plans = CommissionPlan::query()
                ->activeAt($earnedAt)
                ->where('beneficiary_role', $role)
                ->where('credit_policy', CommissionPlan::CREDIT_SALE_CONFIRMATION)
                ->whereHas('assignments', fn ($query) => $query
                    ->where('user_id', $userId)
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $earnedAt))
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $earnedAt)))
                ->get();

            foreach ($plans as $plan) {
                if ($role === CommissionPlan::ROLE_CASHIER
                    && $order->seller_id === $order->cashier_id
                    && ! $plan->allow_self_stacking) {
                    continue;
                }
                foreach ($order->sale->items as $saleItem) {
                    $this->recordEarning($order, $saleItem, $plan, (int) $userId, $earnedAt);
                }
            }
        }
    }

    public function recordReceivablePayment(AccountsReceivable $account, AccountsReceivablePayment $payment): void
    {
        $order = PosOrder::query()->where('sale_id', $account->sale_id)->first();
        if (! $order || (float) $account->original_base_amount <= 0) {
            return;
        }

        $order->loadMissing('sale.items');
        $earnedAt = $payment->paid_at ?? $payment->created_at ?? now();
        $ratio = min(1.0, (float) $payment->amount_base / (float) $account->original_base_amount);
        $beneficiaries = [
            CommissionPlan::ROLE_SELLER => $order->seller_id,
            CommissionPlan::ROLE_CASHIER => $order->cashier_id,
        ];

        foreach ($beneficiaries as $role => $userId) {
            if (! $userId) {
                continue;
            }
            $plans = CommissionPlan::query()
                ->activeAt($earnedAt)
                ->where('beneficiary_role', $role)
                ->where('credit_policy', CommissionPlan::CREDIT_PROPORTIONAL_COLLECTIONS)
                ->whereHas('assignments', fn ($query) => $query->where('user_id', $userId)->where('is_active', true))
                ->get();

            foreach ($plans as $plan) {
                if ($role === CommissionPlan::ROLE_CASHIER && $order->seller_id === $order->cashier_id && ! $plan->allow_self_stacking) {
                    continue;
                }
                foreach ($order->sale->items as $saleItem) {
                    $this->recordEarning($order, $saleItem, $plan, (int) $userId, $earnedAt, $payment, $ratio);
                }
            }
        }
    }

    /**
     * Registra la comision del tecnico al entregar una orden de servicio del
     * Taller. La base es la mano de obra (labor_base_amount); las piezas no
     * suman a la base. Se calcula con los planes activos de rol 'technician'
     * asignados al tecnico, al momento de la entrega.
     */
    public function recordServiceOrder(ServiceOrder $order): void
    {
        if (! $order->technician_id) {
            return;
        }

        $earnedAt = $order->delivered_at ?? $order->completed_at ?? now();
        $base = round((float) $order->labor_base_amount, 4);

        if ($base <= 0) {
            return;
        }

        $plans = CommissionPlan::query()
            ->activeAt($earnedAt)
            ->where('beneficiary_role', CommissionPlan::ROLE_TECHNICIAN)
            ->whereHas('assignments', fn ($query) => $query
                ->where('user_id', $order->technician_id)
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $earnedAt))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $earnedAt)))
            ->get();

        foreach ($plans as $plan) {
            if (CommissionEntry::query()
                ->where('commission_plan_id', $plan->id)
                ->where('service_order_id', $order->id)
                ->where('beneficiary_user_id', $order->technician_id)
                ->where('entry_type', CommissionEntry::TYPE_EARNING)
                ->exists()) {
                continue;
            }

            $entry = CommissionEntry::create([
                'entry_uuid' => (string) Str::uuid(),
                'commission_plan_id' => $plan->id,
                'service_order_id' => $order->id,
                'beneficiary_user_id' => $order->technician_id,
                'beneficiary_role' => CommissionPlan::ROLE_TECHNICIAN,
                'entry_type' => CommissionEntry::TYPE_EARNING,
                'plan_name_snapshot' => $plan->name,
                'percentage_snapshot' => $plan->percentage,
                'source_amount' => $base,
                'eligible_base_amount' => $base,
                'sale_currency' => Product::CURRENCY_USD,
                'commission_base_amount' => round($base * (float) $plan->percentage / 100, 4),
                'status' => CommissionEntry::STATUS_PENDING,
                'earned_at' => $earnedAt,
                'available_at' => $earnedAt->copy()->addDays($plan->maturation_days),
            ]);
            $this->recordSyncEvent($entry);
        }
    }

    public function reverseSalesReturn(SalesReturn $salesReturn): void
    {
        $salesReturn->loadMissing('items.saleItem');
        foreach ($salesReturn->items as $returnItem) {
            $soldQuantity = (float) $returnItem->saleItem->quantity;
            if ($soldQuantity <= 0) {
                continue;
            }
            $ratio = min(1.0, (float) $returnItem->quantity / $soldQuantity);
            $earnings = CommissionEntry::query()
                ->where('sale_item_id', $returnItem->sale_item_id)
                ->where('entry_type', CommissionEntry::TYPE_EARNING)
                ->get();

            foreach ($earnings as $earning) {
                if (CommissionEntry::query()
                    ->where('original_entry_id', $earning->id)
                    ->where('sales_return_id', $salesReturn->id)
                    ->exists()) {
                    continue;
                }
                $entry = CommissionEntry::create([
                    'entry_uuid' => (string) Str::uuid(),
                    'commission_plan_id' => $earning->commission_plan_id,
                    'sale_id' => $earning->sale_id,
                    'pos_order_id' => $earning->pos_order_id,
                    'sale_item_id' => $earning->sale_item_id,
                    'sales_return_id' => $salesReturn->id,
                    'beneficiary_user_id' => $earning->beneficiary_user_id,
                    'beneficiary_role' => $earning->beneficiary_role,
                    'entry_type' => CommissionEntry::TYPE_REVERSAL,
                    'original_entry_id' => $earning->id,
                    'plan_name_snapshot' => $earning->plan_name_snapshot,
                    'percentage_snapshot' => $earning->percentage_snapshot,
                    'sale_currency' => $earning->sale_currency,
                    'source_amount' => -round((float) $earning->source_amount * $ratio, 4),
                    'eligible_base_amount' => -round((float) $earning->eligible_base_amount * $ratio, 4),
                    'exchange_rate_type_id' => $earning->exchange_rate_type_id,
                    'exchange_rate_type_code' => $earning->exchange_rate_type_code,
                    'exchange_rate' => $earning->exchange_rate,
                    'commission_base_amount' => -round((float) $earning->commission_base_amount * $ratio, 4),
                    'status' => CommissionEntry::STATUS_AVAILABLE,
                    'earned_at' => $salesReturn->processed_at ?? now(),
                    'available_at' => $salesReturn->processed_at ?? now(),
                ]);
                $this->recordSyncEvent($entry);
            }
        }
    }

    private function recordEarning(PosOrder $order, SaleItem $saleItem, CommissionPlan $plan, int $userId, $earnedAt, ?AccountsReceivablePayment $payment = null, float $ratio = 1.0): void
    {
        if (CommissionEntry::query()
            ->where('commission_plan_id', $plan->id)
            ->where('sale_item_id', $saleItem->id)
            ->where('beneficiary_user_id', $userId)
            ->where('entry_type', CommissionEntry::TYPE_EARNING)
            ->where('accounts_receivable_payment_id', $payment?->id)
            ->exists()) {
            return;
        }

        $isCombo = $this->isComboLine($saleItem);
        if (! $plan->include_combos && $isCombo) {
            return;
        }

        $hasDiscount = $this->hasDiscount($saleItem, $isCombo);
        if (! $plan->include_discounts && $hasDiscount) {
            return;
        }

        [$eligibleBase, $rateTypeId, $rateTypeCode, $rate] = $this->eligibleSnapshot($saleItem, $plan);
        $eligibleBase = round($eligibleBase * $ratio, 4);
        $entry = CommissionEntry::create([
            'entry_uuid' => (string) Str::uuid(),
            'commission_plan_id' => $plan->id,
            'sale_id' => $order->sale_id,
            'pos_order_id' => $order->id,
            'sale_item_id' => $saleItem->id,
            'accounts_receivable_payment_id' => $payment?->id,
            'beneficiary_user_id' => $userId,
            'beneficiary_role' => $plan->beneficiary_role,
            'entry_type' => CommissionEntry::TYPE_EARNING,
            'plan_name_snapshot' => $plan->name,
            'percentage_snapshot' => $plan->percentage,
            'sale_currency' => $saleItem->sale_currency,
            'source_amount' => $saleItem->total_amount,
            'eligible_base_amount' => $eligibleBase,
            'exchange_rate_type_id' => $rateTypeId,
            'exchange_rate_type_code' => $rateTypeCode,
            'exchange_rate' => $rate,
            'commission_base_amount' => round($eligibleBase * (float) $plan->percentage / 100, 4),
            'status' => CommissionEntry::STATUS_PENDING,
            'earned_at' => $earnedAt,
            'available_at' => $earnedAt->copy()->addDays($plan->maturation_days),
        ]);
        $this->recordSyncEvent($entry);
    }

    private function isComboLine(SaleItem $item): bool
    {
        if ($item->promotion_id === null) {
            return false;
        }

        return $this->promotionScope((int) $item->promotion_id) === Promotion::SCOPE_COMBO;
    }

    private function hasDiscount(SaleItem $item, bool $isCombo): bool
    {
        if ((float) $item->discount_amount > 0 || (float) $item->discount_base_amount > 0) {
            return true;
        }

        // Una promocion que no es combo (oferta de producto, factura o descuento
        // heredado) implica un descuento sobre la linea.
        return $item->promotion_id !== null && ! $isCombo;
    }

    private function promotionScope(int $promotionId): ?string
    {
        return $this->promotionScopeCache[$promotionId]
            ??= Promotion::query()->whereKey($promotionId)->value('scope');
    }

    private function eligibleSnapshot(SaleItem $item, CommissionPlan $plan): array
    {
        if ($item->sale_currency !== Product::CURRENCY_VES || $plan->conversion_policy === CommissionPlan::CONVERSION_SALE_SNAPSHOT) {
            return [
                round((float) $item->base_total_amount, 4),
                $item->exchange_rate_type_id,
                $item->exchange_rate_type_code,
                $item->exchange_rate,
            ];
        }

        $rateType = ExchangeRateType::query()->findOrFail($plan->exchange_rate_type_id);
        $rate = ExchangeRate::query()
            ->where('exchange_rate_type_id', $rateType->id)
            ->where('base_currency', ExchangeRate::BASE_USD)
            ->where('quote_currency', ExchangeRate::QUOTE_VES)
            ->where('is_active', true)
            ->latest('effective_at')
            ->first();
        if (! $rate || (float) $rate->rate <= 0) {
            throw ValidationException::withMessages(['commission' => "El plan {$plan->name} no tiene una tasa activa."]);
        }

        return [
            round((float) $item->total_amount / (float) $rate->rate, 4),
            $rateType->id,
            $rateType->code,
            $rate->rate,
        ];
    }

    public function recordSyncEvent(CommissionEntry $entry): void
    {
        $entry->loadMissing(['beneficiary', 'originalEntry']);
        $this->syncOutbox->record(
            eventType: 'commission_entry.created',
            aggregateType: 'commission_entry',
            aggregateId: $entry->id,
            payload: array_merge($entry->only([
                'entry_uuid', 'sale_id', 'pos_order_id', 'sale_item_id', 'beneficiary_role',
                'plan_name_snapshot', 'percentage_snapshot', 'sale_currency', 'source_amount',
                'eligible_base_amount', 'exchange_rate_type_code', 'exchange_rate',
                'commission_base_amount', 'status', 'earned_at', 'available_at',
                'adjustment_reason', 'approved_at',
                'created_at', 'updated_at',
            ]), [
                'beneficiary_email' => $entry->beneficiary?->email,
                'original_entry_uuid' => $entry->originalEntry?->entry_uuid,
            ]),
            idempotencyKey: "commission_entry.created:{$entry->entry_uuid}"
        );
    }
}
