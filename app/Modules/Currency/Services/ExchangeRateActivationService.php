<?php

namespace App\Modules\Currency\Services;

use App\Modules\Currency\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;

class ExchangeRateActivationService
{
    public function activate(ExchangeRate $rate): ExchangeRate
    {
        return DB::transaction(function () use ($rate): ExchangeRate {
            ExchangeRate::query()
                ->where('exchange_rate_type_id', $rate->exchange_rate_type_id)
                ->where('base_currency', $rate->base_currency)
                ->where('quote_currency', $rate->quote_currency)
                ->whereKeyNot($rate->id)
                ->update(['is_active' => false]);

            $rate->update(['is_active' => true]);

            return $rate->refresh()->load('type');
        });
    }
}
