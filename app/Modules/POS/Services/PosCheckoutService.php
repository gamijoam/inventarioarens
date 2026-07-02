<?php

namespace App\Modules\POS\Services;

use App\Models\User;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosCheckoutService
{
    public function __construct(private readonly SaleService $sales)
    {
    }

    public function checkout(User $cashier, array $items, array $payments, ?string $customerName = null): PosOrder
    {
        return DB::transaction(function () use ($cashier, $items, $payments, $customerName): PosOrder {
            $sale = $this->sales->createDraft($cashier, $items);

            $order = PosOrder::create([
                'sale_id' => $sale->id,
                'status' => PosOrder::STATUS_OPEN,
                'cashier_id' => $cashier->id,
                'customer_name' => $customerName,
                'total_base_amount' => $sale->total_base_amount,
                'total_local_amount' => $sale->total_local_amount,
                'opened_at' => now(),
            ]);

            $paidBase = 0.0;
            $paidLocal = 0.0;

            foreach ($payments as $payment) {
                $resolved = $this->resolvePayment($payment);
                $status = $payment['status'] ?? PosPayment::STATUS_CAPTURED;

                PosPayment::create([
                    'pos_order_id' => $order->id,
                    'method' => $payment['method'],
                    'currency' => strtoupper($payment['currency']),
                    'amount' => $payment['amount'],
                    'amount_base' => $resolved['amount_base'],
                    'amount_local' => $resolved['amount_local'],
                    'exchange_rate_type_id' => $resolved['exchange_rate_type_id'],
                    'exchange_rate_type_code' => $resolved['exchange_rate_type_code'],
                    'exchange_rate' => $resolved['exchange_rate'],
                    'status' => $status,
                    'reference' => $payment['reference'] ?? null,
                    'external_provider' => $payment['external_provider'] ?? null,
                    'metadata' => $payment['metadata'] ?? null,
                ]);

                if ($status === PosPayment::STATUS_CAPTURED) {
                    $paidBase += $resolved['amount_base'];
                    $paidLocal += $resolved['amount_local'] ?? 0.0;
                }
            }

            $order->update([
                'paid_base_amount' => round($paidBase, 4),
                'paid_local_amount' => round($paidLocal, 4),
            ]);

            if ($this->coversTotal($paidBase, (float) $sale->total_base_amount)) {
                $this->sales->confirm($sale, $cashier);
                $order->update([
                    'status' => PosOrder::STATUS_PAID,
                    'paid_at' => now(),
                    'closed_at' => now(),
                ]);
            }

            return $order->refresh()->load(['sale.items.product', 'sale.items.warehouse', 'payments']);
        });
    }

    private function resolvePayment(array $payment): array
    {
        $currency = strtoupper($payment['currency']);
        $amount = (float) $payment['amount'];
        $rateType = null;
        $rate = null;

        if ($currency === Product::CURRENCY_VES || isset($payment['exchange_rate_type_id'])) {
            $rateType = $this->rateTypeFor($payment['exchange_rate_type_id'] ?? null);
            $rate = $this->activeRateFor($rateType);
        }

        $exchangeRate = $rate ? (float) $rate->rate : null;

        if ($currency === Product::CURRENCY_VES && ! $exchangeRate) {
            throw ValidationException::withMessages([
                'payments' => 'El pago en bolivares requiere una tasa activa.',
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
                'payments' => 'No existe un tipo de tasa activo por defecto para el pago.',
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

    private function coversTotal(float $paidBase, float $totalBase): bool
    {
        return round($paidBase, 4) + 0.0001 >= round($totalBase, 4);
    }
}
