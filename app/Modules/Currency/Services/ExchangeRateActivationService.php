<?php

namespace App\Modules\Currency\Services;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Tenancy\Models\Tenant;
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

        $this->broadcastRateUpdate($activated);

        return $activated;
    }

    public function deactivate(ExchangeRate $rate): ExchangeRate
    {
        $deactivated = DB::transaction(function () use ($rate): ExchangeRate {
            $rate->update(['is_active' => false]);

            return $rate->refresh()->load('type');
        });

        $this->broadcastRateUpdate($deactivated);

        return $deactivated;
    }

    public function broadcastRateUpdate(ExchangeRate $rate): void
    {
        $data = [
            'id' => $rate->id,
            'type_code' => $rate->type?->code,
            'type_name' => $rate->type?->name,
            'base_currency' => $rate->base_currency,
            'quote_currency' => $rate->quote_currency,
            'rate' => (float) $rate->rate,
            'is_active' => (bool) $rate->is_active,
            'effective_at' => $rate->effective_at?->toIso8601String(),
        ];

        // 1. Notificar al tenant actual
        WsHub::publishTenant($rate->tenant_id, 'rate.updated', $data);

        // 2. Difusion a grupo e hijas si aplica
        $tenant = Tenant::find($rate->tenant_id);
        if ($tenant) {
            $groupId = $tenant->parent_id ?? ($tenant->isGroup() ? $tenant->id : null);
            if ($groupId) {
                WsHub::publish("group:{$groupId}", 'rate.updated', $data);
            }
            if ($tenant->isGroup()) {
                $childIds = $tenant->children()->pluck('id')->all();
                foreach ($childIds as $childId) {
                    WsHub::publishTenant($childId, 'rate.updated', $data);
                }
            }
        }
    }
}
