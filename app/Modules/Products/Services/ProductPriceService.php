<?php

namespace App\Modules\Products\Services;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Models\Product;
use Illuminate\Validation\ValidationException;

class ProductPriceService
{
    public function quote(Product $product): array
    {
        if ($product->base_price === null) {
            throw ValidationException::withMessages([
                'base_price' => 'El producto no tiene precio base configurado.',
            ]);
        }

        $basePriceUsd = (float) $product->base_price;
        $rateType = $this->rateTypeFor($product);
        $rate = $rateType ? $this->activeRateFor($rateType) : null;

        if ($product->sale_currency === Product::CURRENCY_VES && ! $rate) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'El producto requiere una tasa activa para cotizar en bolivares.',
            ]);
        }

        $exchangeRate = $rate ? (float) $rate->rate : null;
        $priceVes = $exchangeRate === null ? null : round($basePriceUsd * $exchangeRate, 2);

        return [
            'product_id' => $product->id,
            'base_price_usd' => $basePriceUsd,
            'sale_currency' => $product->sale_currency,
            'sale_price' => $product->sale_currency === Product::CURRENCY_VES ? $priceVes : $basePriceUsd,
            'price_usd' => $basePriceUsd,
            'price_ves' => $priceVes,
            'exchange_rate_type_id' => $rateType?->id,
            'exchange_rate_type_code' => $rateType?->code,
            'exchange_rate_type_name' => $rateType?->name,
            'exchange_rate_id' => $rate?->id,
            'exchange_rate' => $exchangeRate,
            'exchange_rate_effective_at' => $rate?->effective_at?->toISOString(),
        ];
    }

    private function rateTypeFor(Product $product): ?ExchangeRateType
    {
        if ($product->sale_exchange_rate_type_id) {
            return $product->saleExchangeRateType()->first();
        }

        return ExchangeRateType::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
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
}
