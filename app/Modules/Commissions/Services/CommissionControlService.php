<?php

namespace App\Modules\Commissions\Services;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\POS\Models\PosOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class CommissionControlService
{
    public function report(array $filters, User $viewer, bool $ownOnly): array
    {
        $paymentMethods = PaymentMethod::query()
            ->where('report_visible', true)
            ->orderBy('report_sort_order')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $orders = PosOrder::query()
            ->where('status', PosOrder::STATUS_PAID)
            ->with([
                'seller:id,name,email',
                'cashier:id,name,email',
                'cashRegisterSession.branch:id,name,code',
                'sale.items.product:id,name,sku',
                'payments.paymentMethod:id,name,code,report_code,report_label',
            ])
            ->when($ownOnly, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('seller_id', $viewer->id)
                ->orWhere('cashier_id', $viewer->id)))
            ->when(isset($filters['user_id']), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('seller_id', $filters['user_id'])
                ->orWhere('cashier_id', $filters['user_id'])))
            ->when(isset($filters['cashier_id']), fn (Builder $query) => $query->where('cashier_id', $filters['cashier_id']))
            ->when(isset($filters['payment_method_id']), fn (Builder $query) => $query->whereHas('payments', fn (Builder $query) => $query
                ->where('payment_method_id', $filters['payment_method_id'])))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('paid_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('paid_at', '<=', Carbon::parse($date)->endOfDay()))
            ->latest('paid_at')
            ->get();

        $orderIds = $orders->pluck('id');
        $entries = CommissionEntry::query()
            ->whereIn('pos_order_id', $orderIds)
            ->get()
            ->groupBy('sale_item_id');
        $rows = [];

        foreach ($orders as $order) {
            $items = $order->sale?->items ?? collect();
            $totalBase = max(0.0001, (float) $items->sum('base_total_amount'));
            $capturedPayments = $order->payments->where('status', 'captured')->values();

            foreach ($items as $item) {
                $ratio = (float) $item->base_total_amount / $totalBase;
                $paymentColumns = [];

                foreach ($capturedPayments as $payment) {
                    $method = $payment->paymentMethod;
                    $key = 'payment_method_'.($method?->id ?? $payment->method);
                    $paymentColumns[$key] ??= [
                        'code' => $method?->report_code ?: $method?->code ?: strtoupper($payment->method),
                        'label' => $method?->report_code ?: $method?->report_label ?: $method?->name ?: strtoupper($payment->method),
                        'amount' => '0.0000',
                        'currency' => $payment->currency,
                        'amount_base' => '0.0000',
                        'amount_local' => '0.0000',
                    ];
                    $paymentColumns[$key]['amount'] = $this->number(
                        (float) $paymentColumns[$key]['amount'] + ((float) $payment->amount * $ratio),
                    );
                    $paymentColumns[$key]['amount_base'] = $this->number(
                        (float) $paymentColumns[$key]['amount_base'] + ((float) $payment->amount_base * $ratio),
                    );
                    $paymentColumns[$key]['amount_local'] = $this->number(
                        (float) $paymentColumns[$key]['amount_local'] + ((float) ($payment->amount_local ?? 0) * $ratio),
                    );
                }

                $itemEntries = $entries->get($item->id, collect());
                $commissionUsd = round((float) $itemEntries->sum('commission_base_amount'), 4);
                $commissionVes = $item->sale_currency === 'VES' && $item->exchange_rate
                    ? round($commissionUsd * (float) $item->exchange_rate, 4)
                    : null;
                $vesPaymentBase = $capturedPayments
                    ->filter(fn ($payment): bool => $payment->currency === 'VES')
                    ->sum(fn ($payment): float => $this->paymentBaseAmount($payment) * $ratio);
                $equivalentUsd = $item->sale_currency === 'VES'
                    ? (float) $item->base_total_amount
                    : ($vesPaymentBase > 0 ? $vesPaymentBase : null);
                $vesPayment = $capturedPayments->first(fn ($payment): bool => $payment->currency === 'VES');
                $exchangeRate = $item->exchange_rate ?: $vesPayment?->exchange_rate;
                $exchangeRateTypeCode = $item->exchange_rate_type_code ?: $vesPayment?->exchange_rate_type_code;
                $financing = $this->financing($capturedPayments);

                $rows[] = [
                    'id' => "order-{$order->id}-item-{$item->id}",
                    'date' => $order->paid_at?->toJSON(),
                    'order_id' => $order->id,
                    'seller' => $this->userSummary($order->seller),
                    'cashier' => $this->userSummary($order->cashier),
                    'branch' => $order->cashRegisterSession?->branch ? [
                        'id' => $order->cashRegisterSession->branch->id,
                        'name' => $order->cashRegisterSession->branch->name,
                        'code' => $order->cashRegisterSession->branch->code,
                    ] : null,
                    'quantity' => $this->number((float) $item->quantity),
                    'product' => [
                        'id' => $item->product?->id,
                        'sku' => $item->product?->sku,
                        'name' => $item->product?->name ?? 'Producto eliminado',
                    ],
                    'sale_currency' => $item->sale_currency,
                    'amount_usd' => $item->sale_currency === 'USD' ? $this->number($item->total_amount) : null,
                    'amount_ves' => $item->sale_currency === 'VES' ? $this->number($item->total_amount) : null,
                    'equivalent_usd' => $equivalentUsd === null ? null : $this->number($equivalentUsd),
                    'exchange_rate_type_code' => $exchangeRateTypeCode,
                    'exchange_rate' => $exchangeRate ? $this->number($exchangeRate, 6) : null,
                    'payment_columns' => $paymentColumns,
                    'financing_method' => $financing['method'],
                    'financing_level' => $financing['level'],
                    'financed_amount' => $financing['amount'],
                    'total' => $this->number($item->total_amount),
                    'commission_usd' => $this->number($commissionUsd),
                    'commission_ves' => $commissionVes === null ? null : $this->number($commissionVes),
                ];
            }
        }

        return [
            'data' => $rows,
            'summary' => $this->summary($rows),
            'meta' => [
                'columns' => $this->columns($paymentMethods),
                'payment_columns' => $paymentMethods->map(fn (PaymentMethod $method): array => [
                    'key' => 'payment_method_'.$method->id,
                    'code' => $method->report_code ?: $method->code,
                    'label' => $method->report_code ?: $method->report_label ?: $method->name,
                ])->values()->all(),
                'total' => count($rows),
            ],
        ];
    }

    private function columns($paymentMethods): array
    {
        $columns = [
            ['key' => 'quantity', 'label' => 'Cant.', 'default_visible' => true],
            ['key' => 'product', 'label' => 'Producto', 'default_visible' => true],
            ['key' => 'amount_usd', 'label' => '$', 'default_visible' => true],
            ['key' => 'amount_ves', 'label' => 'Bs', 'default_visible' => true],
            ['key' => 'equivalent_usd', 'label' => 'Equiv. USD / tasa', 'default_visible' => true],
        ];

        foreach ($paymentMethods as $method) {
            $columns[] = [
                'key' => 'payment_method_'.$method->id,
                'label' => $method->report_code ?: $method->report_label ?: $method->code,
                'default_visible' => true,
            ];
        }

        return array_merge($columns, [
            ['key' => 'financing_method', 'label' => 'Financiamiento', 'default_visible' => true],
            ['key' => 'financing_level', 'label' => 'Nivel', 'default_visible' => true],
            ['key' => 'financed_amount', 'label' => 'Monto financiado', 'default_visible' => true],
            ['key' => 'total', 'label' => 'Total', 'default_visible' => true],
            ['key' => 'commission_usd', 'label' => 'Comision $', 'default_visible' => true],
            ['key' => 'commission_ves', 'label' => 'Comision Bs', 'default_visible' => true],
            ['key' => 'date', 'label' => 'Fecha', 'default_visible' => true],
            ['key' => 'order_id', 'label' => 'Orden', 'default_visible' => true],
            ['key' => 'seller', 'label' => 'Vendedor', 'default_visible' => true],
            ['key' => 'cashier', 'label' => 'Cajero', 'default_visible' => true],
            ['key' => 'branch', 'label' => 'Sede', 'default_visible' => true],
        ]);
    }

    private function summary(array $rows): array
    {
        $paymentTotals = [];
        foreach ($rows as $row) {
            foreach ($row['payment_columns'] as $key => $payment) {
                $paymentTotals[$key] ??= [
                    'code' => $payment['code'],
                    'label' => $payment['label'],
                    'amount' => '0.0000',
                    'currency' => $payment['currency'],
                    'amount_base' => '0.0000',
                    'amount_local' => '0.0000',
                ];
                $paymentTotals[$key]['amount'] = $this->number((float) $paymentTotals[$key]['amount'] + (float) $payment['amount']);
                $paymentTotals[$key]['amount_base'] = $this->number((float) $paymentTotals[$key]['amount_base'] + (float) $payment['amount_base']);
                $paymentTotals[$key]['amount_local'] = $this->number((float) $paymentTotals[$key]['amount_local'] + (float) $payment['amount_local']);
            }
        }

        return [
            'quantity' => $this->number(array_sum(array_map(fn (array $row): float => (float) $row['quantity'], $rows))),
            'amount_usd' => $this->number(array_sum(array_map(fn (array $row): float => (float) ($row['amount_usd'] ?? 0), $rows))),
            'amount_ves' => $this->number(array_sum(array_map(fn (array $row): float => (float) ($row['amount_ves'] ?? 0), $rows))),
            'equivalent_usd' => $this->number(array_sum(array_map(fn (array $row): float => (float) ($row['equivalent_usd'] ?? 0), $rows))),
            'total' => $this->number(array_sum(array_map(fn (array $row): float => (float) $row['total'], $rows))),
            'commission_usd' => $this->number(array_sum(array_map(fn (array $row): float => (float) $row['commission_usd'], $rows))),
            'commission_ves' => $this->number(array_sum(array_map(fn (array $row): float => (float) ($row['commission_ves'] ?? 0), $rows))),
            'payment_columns' => $paymentTotals,
        ];
    }

    private function financing($payments): array
    {
        $payment = $payments->first(fn ($payment): bool => $payment->method === 'external_financing');
        if (! $payment) {
            return ['method' => null, 'level' => null, 'amount' => null];
        }

        $metadata = is_array($payment->metadata) ? $payment->metadata : [];

        return [
            'method' => $payment->external_provider ?: ($metadata['financing_method'] ?? null),
            'level' => $metadata['financing_level'] ?? null,
            'amount' => $this->number((float) $payment->amount),
        ];
    }

    private function paymentBaseAmount($payment): float
    {
        if ($payment->amount_base !== null) {
            return (float) $payment->amount_base;
        }

        if ($payment->currency === 'VES' && (float) $payment->exchange_rate > 0) {
            return (float) $payment->amount / (float) $payment->exchange_rate;
        }

        return (float) $payment->amount;
    }

    private function userSummary($user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null;
    }

    private function number($value, int $scale = 4): string
    {
        return number_format((float) $value, $scale, '.', '');
    }
}
