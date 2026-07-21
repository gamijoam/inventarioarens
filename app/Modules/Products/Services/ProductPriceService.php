<?php

namespace App\Modules\Products\Services;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use Illuminate\Validation\ValidationException;

class ProductPriceService
{
    public const PRICE_SOURCE_BASE = 'base';

    public const PRICE_SOURCE_LIST = 'list';

    public function quote(Product $product, ?int $priceListId = null, ?string $priceSource = null): array
    {
        $source = $this->resolvePriceSource($priceListId, $priceSource);
        $productPrice = $source === self::PRICE_SOURCE_BASE
            ? null
            : $this->productPriceFor($product, $priceListId);

        if ($source === self::PRICE_SOURCE_BASE && $product->base_price === null) {
            throw ValidationException::withMessages([
                'base_price' => 'El producto no tiene precio base configurado.',
            ]);
        }

        $price = $productPrice ? (float) $productPrice->price : (float) $product->base_price;
        $rateType = $this->rateTypeFor($product, $productPrice);
        $rate = $rateType ? $this->activeRateFor($rateType) : null;
        $requiresRate = $productPrice
            ? $productPrice->currency === Product::CURRENCY_VES
            : $product->sale_currency === Product::CURRENCY_VES;

        if ($requiresRate && ! $rate) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'El producto requiere una tasa activa para cotizar en bolívares.',
            ]);
        }

        $exchangeRate = $rate ? (float) $rate->rate : null;
        $basePriceUsd = $productPrice?->currency === Product::CURRENCY_VES
            ? round($price / (float) $exchangeRate, 4)
            : $price;
        $priceVes = $exchangeRate === null ? null : round($basePriceUsd * $exchangeRate, 2);
        $saleCurrency = $productPrice?->currency ?? $product->sale_currency;
        $salePrice = $productPrice
            ? $price
            : ($product->sale_currency === Product::CURRENCY_VES ? $priceVes : $basePriceUsd);

        return [
            'product_id' => $product->id,
            'price_list_id' => $productPrice?->price_list_id,
            'price_list_name' => $productPrice?->priceList?->name,
            'price_source' => $productPrice ? self::PRICE_SOURCE_LIST : self::PRICE_SOURCE_BASE,
            'base_price_usd' => $basePriceUsd,
            'sale_currency' => $saleCurrency,
            'sale_price' => $salePrice,
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

    private function resolvePriceSource(?int $priceListId, ?string $priceSource): string
    {
        $normalized = is_string($priceSource) ? strtolower(trim($priceSource)) : null;

        if ($normalized === self::PRICE_SOURCE_BASE) {
            return self::PRICE_SOURCE_BASE;
        }

        if ($normalized === self::PRICE_SOURCE_LIST) {
            if ($priceListId === null) {
                throw ValidationException::withMessages([
                    'price_list_id' => 'price_source=list requiere una lista de precios.',
                ]);
            }

            return self::PRICE_SOURCE_LIST;
        }

        return self::PRICE_SOURCE_LIST;
    }

    private function productPriceFor(Product $product, ?int $priceListId): ?ProductPrice
    {
        if ($priceListId) {
            $price = ProductPrice::query()
                ->with('priceList')
                ->where('product_id', $product->id)
                ->where('price_list_id', $priceListId)
                ->where('is_active', true)
                ->whereHas('priceList', fn ($query) => $query->where('is_active', true))
                ->first();

            if (! $price) {
                throw ValidationException::withMessages([
                    'price_list_id' => 'Este producto no tiene precio en esta lista.',
                ]);
            }

            return $price;
        }

        $defaultPriceList = PriceList::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if (! $defaultPriceList) {
            return null;
        }

        return ProductPrice::query()
            ->with('priceList')
            ->where('product_id', $product->id)
            ->where('price_list_id', $defaultPriceList->id)
            ->where('is_active', true)
            ->first();
    }

    private function rateTypeFor(Product $product, ?ProductPrice $productPrice): ?ExchangeRateType
    {
        if ($productPrice?->exchange_rate_type_id) {
            return $productPrice->exchangeRateType()->first();
        }

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
