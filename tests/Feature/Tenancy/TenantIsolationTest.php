<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_scoped_models_only_read_current_tenant_records(): void
    {
        [$tenantA, $tenantB] = $this->tenants();

        app(TenantManager::class)->set($tenantA);
        Product::create(['name' => 'Redmi A3', 'sku' => 'REDMI-A3']);

        app(TenantManager::class)->set($tenantB);
        Product::create(['name' => 'Samsung A15', 'sku' => 'SAMSUNG-A15']);

        $this->assertSame(['Samsung A15'], Product::query()->pluck('name')->all());

        app(TenantManager::class)->set($tenantA);
        $this->assertSame(['Redmi A3'], Product::query()->pluck('name')->all());
    }

    public function test_tenant_id_is_required_when_creating_scoped_business_data(): void
    {
        app(TenantManager::class)->clear();

        $this->expectException(TenantNotResolvedException::class);

        Product::create(['name' => 'Unscoped product', 'sku' => 'UNSCOPED']);
    }

    public function test_same_sku_can_exist_in_different_tenants(): void
    {
        [$tenantA, $tenantB] = $this->tenants();

        app(TenantManager::class)->set($tenantA);
        Product::create(['name' => 'Redmi A3', 'sku' => 'REDMI-A3']);

        app(TenantManager::class)->set($tenantB);
        Product::create(['name' => 'Redmi A3', 'sku' => 'REDMI-A3']);

        $this->assertDatabaseCount('products', 2);
    }

    private function tenants(): array
    {
        return [
            Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']),
            Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']),
        ];
    }
}
