<?php

namespace App\Modules\CashRegister\Resources;

use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CashRegisterSession */
class CashRegisterSessionDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $movements = $this->movements;
        $orders = $this->posOrders;
        $payments = $orders->flatMap->payments;

        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'cash_register_id' => $this->cash_register_id,
            'cashier_id' => $this->cashier_id,
            'opened_by' => $this->opened_by,
            'closed_by' => $this->closed_by,
            'status' => $this->status,
            'opening_base_amount' => $this->opening_base_amount,
            'opening_local_amount' => $this->opening_local_amount,
            'expected_base_amount' => $this->expected_base_amount,
            'expected_local_amount' => $this->expected_local_amount,
            'expected_cash_usd' => $this->expected_cash_usd,
            'expected_cash_ves' => $this->expected_cash_ves,
            'counted_base_amount' => $this->counted_base_amount,
            'counted_local_amount' => $this->counted_local_amount,
            'counted_cash_usd' => $this->counted_cash_usd,
            'counted_cash_ves' => $this->counted_cash_ves,
            'difference_base_amount' => $this->difference_base_amount,
            'difference_local_amount' => $this->difference_local_amount,
            'difference_cash_usd' => $this->difference_cash_usd,
            'difference_cash_ves' => $this->difference_cash_ves,
            'opened_at' => $this->opened_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'notes' => $this->notes,
            'closing_notes' => $this->closing_notes,
            'counting_mode' => $this->counting_mode,
            'review_status' => $this->review_status,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'review_notes' => $this->review_notes,
            'counts' => $this->whenLoaded('counts', fn () => $this->counts->map(fn ($count) => [
                'currency' => $count->currency,
                'denomination' => $count->denomination,
                'quantity' => $count->quantity,
                'total_amount' => $count->total_amount,
            ])->values()),
            'branch' => $this->whenLoaded('branch'),
            'cash_register' => CashRegisterResource::make($this->whenLoaded('cashRegister')),
            'cashier' => $this->whenLoaded('cashier', fn () => $this->person($this->cashier)),
            'closer' => $this->whenLoaded('closer', fn () => $this->person($this->closer)),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->person($this->reviewer)),
            'summary' => [
                'movement_count' => $movements->count(),
                'pos_order_count' => $orders->count(),
                'pos_paid_order_count' => $orders->where('status', PosOrder::STATUS_PAID)->count(),
                'pos_paid_base_amount' => round((float) $orders->where('status', PosOrder::STATUS_PAID)->sum('paid_base_amount'), 4),
                'pos_paid_local_amount' => round((float) $orders->where('status', PosOrder::STATUS_PAID)->sum('paid_local_amount'), 4),
                'receivable_collections_base_amount' => $this->movementSourceTotal($movements, 'AccountsReceivablePayment'),
                'payable_payments_base_amount' => $this->movementSourceTotal($movements, 'AccountsPayablePayment'),
                'manual_movement_count' => $movements->whereNull('source_type')->count(),
            ],
            'payment_breakdown' => $payments
                ->where('status', 'captured')
                ->groupBy(fn ($payment) => ($payment->paymentMethod?->name ?? $payment->method).'|'.$payment->currency)
                ->map(fn ($group) => [
                    'name' => $group->first()->paymentMethod?->name ?? $group->first()->method,
                    'method' => $group->first()->method,
                    'currency' => $group->first()->currency,
                    'payments_count' => $group->count(),
                    'amount_base' => round((float) $group->sum('amount_base'), 4),
                    'amount_local' => round((float) $group->sum('amount_local'), 4),
                ])
                ->values()
                ->all(),
            'movements' => CashRegisterMovementResource::collection($movements),
            'orders' => $orders->map(fn (PosOrder $order) => [
                'id' => $order->id,
                'status' => $order->status,
                'cashier_name' => $order->cashier?->name,
                'customer_name' => $order->customer?->name ?? $order->customer_name,
                'total_base_amount' => $order->total_base_amount,
                'total_local_amount' => $order->total_local_amount,
                'paid_base_amount' => $order->paid_base_amount,
                'paid_local_amount' => $order->paid_local_amount,
                'opened_at' => $order->opened_at?->toISOString(),
                'paid_at' => $order->paid_at?->toISOString(),
                'payments' => $order->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'name' => $payment->paymentMethod?->name ?? $payment->method,
                    'method' => $payment->method,
                    'currency' => $payment->currency,
                    'amount' => $payment->amount,
                    'amount_base' => $payment->amount_base,
                    'amount_local' => $payment->amount_local,
                    'reference' => $payment->reference,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function movementSourceTotal($movements, string $source): float
    {
        return round((float) $movements
            ->filter(fn (CashRegisterMovement $movement) => str_ends_with((string) $movement->source_type, $source))
            ->sum('amount_base'), 4);
    }

    private function person(?object $person): ?array
    {
        return $person ? ['id' => $person->id, 'name' => $person->name, 'email' => $person->email] : null;
    }
}
