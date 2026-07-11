<?php

namespace Tests\Feature\Performance;

use App\Models\User;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\PriceList;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Cache\TenantReferenceCache;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantReferenceCacheTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Tienda Cache', 'slug' => 'tienda-cache']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        return $tenant;
    }

    public function test_active_payment_methods_caches_and_returns_collection(): void
    {
        $tenant = $this->makeTenant();
        PaymentMethod::create(['tenant_id' => $tenant->id, 'name' => 'Efectivo', 'code' => 'CASH', 'method' => 'cash', 'currency_mode' => 'USD', 'is_active' => true]);
        PaymentMethod::create(['tenant_id' => $tenant->id, 'name' => 'Zelle', 'code' => 'ZELLE', 'method' => 'zelle', 'currency_mode' => 'USD', 'is_active' => true]);
        PaymentMethod::create(['tenant_id' => $tenant->id, 'name' => 'Inactivo', 'code' => 'OFF', 'method' => 'other', 'currency_mode' => 'USD', 'is_active' => false]);

        $cache = app(TenantReferenceCache::class);

        $first = $cache->activePaymentMethods($tenant->id);
        $this->assertCount(2, $first);

        $second = $cache->activePaymentMethods($tenant->id);
        $this->assertCount(2, $second);
        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
    }

    public function test_active_payment_methods_isolated_per_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        DB::table('payment_methods')->insert([
            'tenant_id' => $tenantA->id,
            'name' => 'PM A',
            'code' => 'PM-A',
            'method' => 'cash',
            'currency_mode' => 'USD',
            'is_active' => true,
            'requires_reference' => false,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_methods')->insert([
            ['tenant_id' => $tenantB->id, 'name' => 'PM B1', 'code' => 'PM-B1', 'method' => 'cash', 'currency_mode' => 'USD', 'is_active' => true, 'requires_reference' => false, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenantB->id, 'name' => 'PM B2', 'code' => 'PM-B2', 'method' => 'card', 'currency_mode' => 'USD', 'is_active' => true, 'requires_reference' => false, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $cache = app(TenantReferenceCache::class);

        $forA = $cache->activePaymentMethods($tenantA->id);
        $forB = $cache->activePaymentMethods($tenantB->id);

        $this->assertCount(1, $forA);
        $this->assertCount(2, $forB);
    }

    public function test_default_price_list_is_cached(): void
    {
        $tenant = $this->makeTenant();
        PriceList::create(['tenant_id' => $tenant->id, 'name' => 'Default', 'code' => 'DEF', 'is_default' => true, 'is_active' => true]);
        PriceList::create(['tenant_id' => $tenant->id, 'name' => 'Other', 'code' => 'OTH', 'is_default' => false, 'is_active' => true]);

        $cache = app(TenantReferenceCache::class);

        $this->assertSame('Default', $cache->defaultPriceList($tenant->id)->name);
        $this->assertSame('Default', $cache->defaultPriceList($tenant->id)->name);
    }

    public function test_active_exchange_rate_caches_with_shorter_ttl(): void
    {
        $tenant = $this->makeTenant();
        $rateType = ExchangeRateType::create(['tenant_id' => $tenant->id, 'code' => 'BCV', 'name' => 'Tasa BCV', 'is_default' => true, 'is_active' => true]);
        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'exchange_rate_type_id' => $rateType->id,
            'base_currency' => 'USD',
            'quote_currency' => 'VES',
            'rate' => 36.50,
            'effective_at' => now(),
            'is_active' => true,
        ]);

        $cache = app(TenantReferenceCache::class);

        $rate1 = $cache->activeExchangeRate($tenant->id, $rateType->id);
        $this->assertSame('36.500000', (string) $rate1->rate);

        $rate2 = $cache->activeExchangeRate($tenant->id, $rateType->id);
        $this->assertSame($rate1->id, $rate2->id);
    }

    public function test_active_exchange_rate_types_includes_default_first(): void
    {
        $tenant = $this->makeTenant();
        ExchangeRateType::create(['tenant_id' => $tenant->id, 'code' => 'PAR', 'name' => 'Paralelo', 'is_default' => false, 'is_active' => true]);
        $rateType = ExchangeRateType::create(['tenant_id' => $tenant->id, 'code' => 'BCV', 'name' => 'BCV', 'is_default' => true, 'is_active' => true]);

        $cache = app(TenantReferenceCache::class);
        $types = $cache->activeExchangeRateTypes($tenant->id);

        $this->assertSame('BCV', $types->first()->code);
        $this->assertSame($rateType->id, $types->first()->id);
    }

    public function test_cache_invalidation_on_payment_method_update(): void
    {
        $tenant = $this->makeTenant();
        $pm = PaymentMethod::create(['tenant_id' => $tenant->id, 'name' => 'Efectivo', 'code' => 'CASH', 'method' => 'cash', 'currency_mode' => 'USD', 'is_active' => true]);

        $cache = app(TenantReferenceCache::class);
        $first = $cache->activePaymentMethods($tenant->id);
        $this->assertCount(1, $first);

        $pm->update(['name' => 'Efectivo Actualizado']);
        Cache::flush();

        $second = $cache->activePaymentMethods($tenant->id);
        $this->assertSame('Efectivo Actualizado', $second->first()->name);
    }

    public function test_active_exchange_rate_invalidates_when_rate_changes(): void
    {
        $tenant = $this->makeTenant();
        $rateType = ExchangeRateType::create(['tenant_id' => $tenant->id, 'code' => 'BCV', 'name' => 'BCV', 'is_default' => true, 'is_active' => true]);
        $rate = ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'exchange_rate_type_id' => $rateType->id,
            'base_currency' => 'USD',
            'quote_currency' => 'VES',
            'rate' => 36.50,
            'effective_at' => now(),
            'is_active' => true,
        ]);

        $cache = app(TenantReferenceCache::class);
        $first = $cache->activeExchangeRate($tenant->id, $rateType->id);
        $this->assertSame('36.500000', (string) $first->rate);

        $rate->update(['rate' => 40.00]);
        Cache::flush();

        $second = $cache->activeExchangeRate($tenant->id, $rateType->id);
        $this->assertSame('40.000000', (string) $second->rate);
    }

    public function test_active_price_lists_with_specific_ids(): void
    {
        $tenant = $this->makeTenant();
        $list1 = PriceList::create(['tenant_id' => $tenant->id, 'name' => 'List 1', 'code' => 'L1', 'is_active' => true]);
        $list2 = PriceList::create(['tenant_id' => $tenant->id, 'name' => 'List 2', 'code' => 'L2', 'is_active' => true]);
        PriceList::create(['tenant_id' => $tenant->id, 'name' => 'List 3', 'code' => 'L3', 'is_active' => true]);

        $cache = app(TenantReferenceCache::class);

        $result = $cache->activePriceLists($tenant->id, [$list1->id, $list2->id]);
        $this->assertCount(2, $result);
        $this->assertEqualsCanonicalizing(
            [$list1->id, $list2->id],
            $result->pluck('id')->all()
        );
    }
}