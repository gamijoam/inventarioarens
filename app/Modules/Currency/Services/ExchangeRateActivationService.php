<?php

namespace App\Modules\Currency\Services;

use App\Modules\Currency\Models\ExchangeRate;
use App\Support\Realtime\WsHub;
use Illuminate\Support\Facades\DB;

class ExchangeRateActivationService
{
    public function activate(ExchangeRate $rate): ExchangeRate
    {
        $activated = DB::transaction(function () use ($rate): ExchangeRate {
            ExchangeRate::query()
                ->where('exchange_rate_type_id', $rate->exchange_rate_type_id)
                ->where('base_currency', $rate->base_currency)
                ->where('quote_currency', $rate->quote_currency)
                ->whereKeyNot($rate->id)
                ->update(['is_active' => false]);

            $rate->update(['is_active' => true]);

            return $rate->refresh()->load('type');
        });

        WsHub::publishTenant($activated->tenant_id, 'rate.updated', [
            'id' => $activated->id,
            'type_code' => $activated->type?->code,
            'type_name' => $activated->type?->name,
            'base_currency' => $activated->base_currency,
            'quote_currency' => $activated->quote_currency,
            'rate' => (float) $activated->rate,
            'effective_at' => $activated->effective_at?->toIso8601String(),
        ]);

        return $activated;
    }
}
