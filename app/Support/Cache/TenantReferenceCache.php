<?php

namespace App\Support\Cache;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\PriceList;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cache de datos de referencia por tenant (read-mostly).
 *
 * Datos cacheados (por tenant, invalidacion automatica via model observers):
 * - active_payment_methods: lista de metodos de pago activos (TTL 10min)
 * - default_price_list: lista default del tenant (TTL 10min)
 * - active_price_lists: listas activas del tenant (TTL 10min)
 * - default_exchange_rate_type: tipo de tasa default (TTL 10min)
 * - active_exchange_rate_types: tipos de tasa activos (TTL 10min)
 * - active_exchange_rate: tasa activa por rate_type_id (TTL 60s, mas volatil)
 *
 * Beneficio: reduce queries repetidas a datos que cambian poco.
 * En el POS que se ejecuta en cada checkout, payment_methods + price_lists +
 * exchange_rates se consultan multiples veces. Con cache, esos datos se cargan
 * una vez cada 10min (o 60s para tasas) en lugar de en cada request.
 */
class TenantReferenceCache
{
    public const TTL_REFERENCE = 600;

    public const TTL_RATE = 60;

    public function activePaymentMethods(int $tenantId): Collection
    {
        return Cache::remember(
            $this->key('active_payment_methods', $tenantId),
            self::TTL_REFERENCE,
            fn () => PaymentMethod::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function defaultPriceList(int $tenantId): ?PriceList
    {
        return Cache::remember(
            $this->key('default_price_list', $tenantId),
            self::TTL_REFERENCE,
            fn () => PriceList::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first()
        );
    }

    public function activePriceLists(int $tenantId, ?array $ids = null): Collection
    {
        if ($ids === null || $ids === []) {
            return Cache::remember(
                $this->key('active_price_lists', $tenantId),
                self::TTL_REFERENCE,
                fn () => PriceList::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get()
            );
        }

        sort($ids);
        $cacheKey = $this->key('active_price_list_ids', $tenantId).':'.md5(json_encode($ids));

        return Cache::remember(
            $cacheKey,
            self::TTL_REFERENCE,
            fn () => PriceList::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereIn('id', $ids)
                ->with('paymentMethods')
                ->get()
        );
    }

    public function defaultExchangeRateType(int $tenantId): ?ExchangeRateType
    {
        return Cache::remember(
            $this->key('default_exchange_rate_type', $tenantId),
            self::TTL_REFERENCE,
            fn () => ExchangeRateType::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first()
        );
    }

    public function activeExchangeRateTypes(int $tenantId): Collection
    {
        return Cache::remember(
            $this->key('active_exchange_rate_types', $tenantId),
            self::TTL_REFERENCE,
            fn () => ExchangeRateType::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get()
        );
    }

    public function activeExchangeRate(int $tenantId, int $rateTypeId): ?ExchangeRate
    {
        return Cache::remember(
            $this->key('active_exchange_rate', $tenantId).':'.$rateTypeId,
            self::TTL_RATE,
            fn () => ExchangeRate::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('exchange_rate_type_id', $rateTypeId)
                ->where('base_currency', ExchangeRate::BASE_USD)
                ->where('quote_currency', ExchangeRate::QUOTE_VES)
                ->where('is_active', true)
                ->latest('effective_at')
                ->first()
        );
    }

    public function forgetTenant(int $tenantId): void
    {
        $prefixes = [
            'active_payment_methods',
            'default_price_list',
            'active_price_lists',
            'default_exchange_rate_type',
            'active_exchange_rate_types',
            'active_exchange_rate',
            'active_price_list_ids',
        ];

        foreach ($prefixes as $prefix) {
            $store = Cache::getStore();
            if (method_exists($store, 'connection')) {
                continue;
            }
            Cache::forget($this->key($prefix, $tenantId));
        }
    }

    private function key(string $name, int $tenantId): string
    {
        return "tenant_ref:{$tenantId}:{$name}";
    }
}
