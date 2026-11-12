<?php

namespace App\Modules\Currency\Services;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Tasa de liquidacion de cobros, pagos y ajustes: siempre la tasa oficial
 * vigente del tipo de tasa (o del tipo por defecto). Una tasa enviada por el
 * cliente solo se acepta si coincide con la oficial.
 */
class SettlementRateResolver
{
    private const RATE_TOLERANCE = 0.000001;

    /**
     * @return array{0: ?ExchangeRateType, 1: ?float, 2: float, 3: float}
     */
    public function resolve(string $currency, float $amount, ?int $rateTypeId, ?float $exchangeRate, string $operation): array
    {
        $currency = strtoupper($currency);
        $rateType = $rateTypeId
            ? ExchangeRateType::query()->findOrFail($rateTypeId)
            : ExchangeRateType::query()->where('is_default', true)->where('is_active', true)->first();
        $officialRate = $rateType ? $this->activeRateFor($rateType) : null;

        if ($exchangeRate !== null && $exchangeRate > 0.0) {
            $this->assertMatchesOfficialRate($exchangeRate, $officialRate, $rateType);
        }

        if ($currency === Product::CURRENCY_USD) {
            return [
                $officialRate ? $rateType : null,
                $officialRate,
                round($amount, 4),
                $officialRate ? round($amount * $officialRate, 4) : 0.0,
            ];
        }

        if (! $rateType) {
            throw ValidationException::withMessages([
                'exchange_rate_type_id' => "{$operation} en bolivares requiere un tipo de tasa activo.",
            ]);
        }

        if (! $officialRate) {
            throw ValidationException::withMessages([
                'exchange_rate' => "{$operation} en bolivares requiere una tasa activa mayor que cero.",
            ]);
        }

        return [$rateType, $officialRate, round($amount / $officialRate, 4), round($amount, 4)];
    }

    private function assertMatchesOfficialRate(float $exchangeRate, ?float $officialRate, ?ExchangeRateType $rateType): void
    {
        if ($officialRate === null) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'No hay una tasa oficial vigente para validar la tasa indicada. Registra la tasa del dia antes de continuar.',
            ]);
        }

        if (abs($exchangeRate - $officialRate) > self::RATE_TOLERANCE) {
            $name = $rateType?->code ?? 'por defecto';

            throw ValidationException::withMessages([
                'exchange_rate' => "La tasa indicada ({$exchangeRate}) no coincide con la tasa oficial vigente {$name} ({$officialRate}). Actualiza la tasa del dia e intenta de nuevo.",
            ]);
        }
    }

    private function activeRateFor(ExchangeRateType $rateType): ?float
    {
        $rate = ExchangeRate::query()
            ->where('exchange_rate_type_id', $rateType->id)
            ->where('base_currency', ExchangeRate::BASE_USD)
            ->where('quote_currency', ExchangeRate::QUOTE_VES)
            ->where('is_active', true)
            ->latest('effective_at')
            ->value('rate');

        return $rate !== null && (float) $rate > 0.0 ? (float) $rate : null;
    }
}
