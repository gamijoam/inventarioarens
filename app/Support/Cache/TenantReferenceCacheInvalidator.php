<?php

namespace App\Support\Cache;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\PriceList;
use Illuminate\Support\Facades\Cache;

/**
 * Invalida el cache de TenantReferenceCache cuando un modelo de referencia
 * (PaymentMethod, PriceList, ExchangeRateType, ExchangeRate) se crea,
 * actualiza o elimina. Registrado en AppServiceProvider::boot().
 */
class TenantReferenceCacheInvalidator
{
    public function __construct(private readonly TenantReferenceCache $cache) {}

    public static function register(): void
    {
        $invalidator = app(self::class);

        PaymentMethod::created(fn () => $invalidator->invalidateAll());
        PaymentMethod::updated(fn () => $invalidator->invalidateAll());
        PaymentMethod::deleted(fn () => $invalidator->invalidateAll());

        PriceList::created(fn () => $invalidator->invalidateAll());
        PriceList::updated(fn () => $invalidator->invalidateAll());
        PriceList::deleted(fn () => $invalidator->invalidateAll());

        ExchangeRateType::created(fn () => $invalidator->invalidateAll());
        ExchangeRateType::updated(fn () => $invalidator->invalidateAll());
        ExchangeRateType::deleted(fn () => $invalidator->invalidateAll());

        ExchangeRate::created(fn () => $invalidator->invalidateRates());
        ExchangeRate::updated(fn () => $invalidator->invalidateRates());
        ExchangeRate::deleted(fn () => $invalidator->invalidateRates());
    }

    public function invalidateAll(): void
    {
        $store = Cache::getStore();

        if (method_exists($store, 'getRedis')) {
            $prefix = config('cache.prefix', '').'tenant_ref:';
            $keys = $store->connection()->keys($prefix.'*');
            if (! empty($keys)) {
                $store->connection()->del($keys);
            }

            return;
        }

        foreach ($this->allTenants() as $tenantId) {
            $this->cache->forgetTenant($tenantId);
        }
    }

    public function invalidateRates(): void
    {
        $store = Cache::getStore();

        if (method_exists($store, 'getRedis')) {
            $prefix = config('cache.prefix', '').'tenant_ref:';
            $pattern = $prefix.'*active_exchange_rate*';
            $keys = $store->connection()->keys($pattern);
            if (! empty($keys)) {
                $store->connection()->del($keys);
            }

            return;
        }

        $this->invalidateAll();
    }

    private function allTenants(): array
    {
        return \App\Modules\Tenancy\Models\Tenant::query()->pluck('id')->all();
    }
}
