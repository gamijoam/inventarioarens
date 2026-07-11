<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Products\Models\PriceList;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Sync\Services\SyncOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CatalogWriteTransactionAtomicityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_customer_store_rolls_back_when_sync_outbox_fails(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda A', 'slug' => 'tienda-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin', ['customers.create', 'customers.view', 'customers.update', 'customers.delete']);

        $this->mock(SyncCatalogOutboxService::class, function ($mock): void {
            $mock->shouldReceive('customerCreated')->once()
                ->andThrow(new \RuntimeException('Outbox unavailable'));
        });

        $countBefore = Customer::query()->where('tenant_id', $tenant->id)->count();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/customers', [
                'name' => 'Cliente Test Atomicidad',
                'document_type' => 'V',
                'document_number' => '12345678',
                'phone' => '04140000000',
            ]);

        $this->assertSame(500, $response->getStatusCode(), 'Debe retornar 500 cuando el outbox falla');

        $countAfter = Customer::query()->where('tenant_id', $tenant->id)->count();
        $this->assertSame($countBefore, $countAfter, 'Customer NO debe haberse creado (rollback)');

        $this->assertDatabaseMissing('customers', [
            'tenant_id' => $tenant->id,
            'document_number' => '12345678',
        ]);
    }

    public function test_customer_update_rolls_back_when_sync_outbox_fails(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda B', 'slug' => 'tienda-b']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin', ['customers.view', 'customers.update', 'customers.create']);

        $customer = $this->useTenant($tenant, function (): Customer {
            return Customer::create([
                'name' => 'Cliente Original',
                'document_type' => 'V',
                'document_number' => '11111111',
                'is_active' => true,
            ]);
        });

        $this->mock(SyncCatalogOutboxService::class, function ($mock): void {
            $mock->shouldReceive('customerUpdated')->once()
                ->andThrow(new \RuntimeException('Outbox unavailable'));
        });

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/customers/{$customer->id}", [
                'name' => 'Cliente Modificado',
                'document_type' => 'V',
                'document_number' => '11111111',
            ]);

        $this->assertSame(500, $response->getStatusCode());

        $customer->refresh();
        $this->assertSame('Cliente Original', $customer->name, 'Nombre NO debe haberse modificado (rollback)');
    }

    public function test_customer_destroy_rolls_back_when_sync_outbox_fails(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda C', 'slug' => 'tienda-c']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin', ['customers.view', 'customers.delete', 'customers.create']);

        $customer = $this->useTenant($tenant, function (): Customer {
            return Customer::create([
                'name' => 'Cliente Activo',
                'document_type' => 'V',
                'document_number' => '22222222',
                'is_active' => true,
            ]);
        });

        $this->mock(SyncCatalogOutboxService::class, function ($mock): void {
            $mock->shouldReceive('customerDeactivated')->once()
                ->andThrow(new \RuntimeException('Outbox unavailable'));
        });

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/customers/{$customer->id}");

        $this->assertSame(500, $response->getStatusCode());

        $customer->refresh();
        $this->assertTrue((bool) $customer->is_active, 'is_active NO debe haberse cambiado a false (rollback)');
    }

    public function test_product_store_rolls_back_when_sync_outbox_fails(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda P1', 'slug' => 'tienda-p1']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin', ['products.view', 'products.create', 'products.update', 'products.delete']);

        $this->useTenant($tenant, function (): void {
            Branch::create(['name' => 'Principal', 'code' => 'MAIN-P1']);
        });

        $this->mock(SyncCatalogOutboxService::class, function ($mock): void {
            $mock->shouldReceive('productCreated')->once()
                ->andThrow(new \RuntimeException('Outbox unavailable'));
        });

        $countBefore = Product::query()->where('tenant_id', $tenant->id)->count();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products', [
                'name' => 'Producto Test Atomicidad',
                'tracking_type' => Product::TRACKING_QUANTITY,
                'base_price' => 100,
                'sale_currency' => Product::CURRENCY_USD,
            ]);

        $this->assertSame(500, $response->getStatusCode());

        $countAfter = Product::query()->where('tenant_id', $tenant->id)->count();
        $this->assertSame($countBefore, $countAfter, 'Product NO debe haberse creado (rollback)');
    }

    public function test_product_update_rolls_back_when_sync_outbox_fails(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda P2', 'slug' => 'tienda-p2']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin', ['products.view', 'products.create', 'products.update']);

        $product = $this->useTenant($tenant, function (): Product {
            return Product::create([
                'name' => 'Producto Original',
                'sku' => 'PROD-ORIG',
                'tracking_type' => Product::TRACKING_QUANTITY,
                'base_price' => 50,
                'sale_currency' => Product::CURRENCY_USD,
            ]);
        });

        $this->mock(SyncCatalogOutboxService::class, function ($mock): void {
            $mock->shouldReceive('productUpdated')->once()
                ->andThrow(new \RuntimeException('Outbox unavailable'));
        });

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/products/{$product->id}", [
                'name' => 'Producto Modificado',
                'tracking_type' => Product::TRACKING_QUANTITY,
                'base_price' => 75,
                'sale_currency' => Product::CURRENCY_USD,
            ]);

        $this->assertSame(500, $response->getStatusCode());

        $product->refresh();
        $this->assertSame('Producto Original', $product->name, 'Nombre NO debe haberse modificado (rollback)');
        $this->assertSame('50.0000', (string) $product->base_price, 'Precio NO debe haberse modificado (rollback)');
    }

    public function test_product_destroy_rolls_back_when_sync_outbox_fails(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda P3', 'slug' => 'tienda-p3']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin', ['products.view', 'products.create', 'products.delete']);

        $product = $this->useTenant($tenant, function (): Product {
            return Product::create([
                'name' => 'Producto a Desactivar',
                'sku' => 'PROD-DEACT',
                'tracking_type' => Product::TRACKING_QUANTITY,
                'base_price' => 100,
                'sale_currency' => Product::CURRENCY_USD,
                'is_active' => true,
            ]);
        });

        $this->mock(SyncCatalogOutboxService::class, function ($mock): void {
            $mock->shouldReceive('productDeactivated')->once()
                ->andThrow(new \RuntimeException('Outbox unavailable'));
        });

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/products/{$product->id}");

        $this->assertSame(500, $response->getStatusCode());

        $product->refresh();
        $this->assertTrue((bool) $product->is_active, 'is_active NO debe haberse cambiado (rollback)');
    }

    public function test_product_price_sync_rolls_back_all_changes_when_outbox_fails(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda PP', 'slug' => 'tienda-pp']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin', ['products.view', 'products.create', 'products.update']);

        [$product, $priceList] = $this->useTenant($tenant, function (): array {
            $product = Product::create([
                'name' => 'Producto PP',
                'sku' => 'PROD-PP',
                'tracking_type' => Product::TRACKING_QUANTITY,
                'base_price' => 100,
                'sale_currency' => Product::CURRENCY_USD,
            ]);
            $priceList = PriceList::create([
                'name' => 'Lista Detal',
                'code' => 'DETAL-PP',
                'is_default' => true,
                'is_active' => true,
            ]);

            return [$product, $priceList];
        });

        $this->mock(SyncCatalogOutboxService::class, function ($mock): void {
            $mock->shouldReceive('productPriceCreated')->once()
                ->andThrow(new \RuntimeException('Outbox unavailable'));
        });

        $countBefore = ProductPrice::query()
            ->where('product_id', $product->id)
            ->count();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/products/{$product->id}/prices", [
                'prices' => [
                    [
                        'price_list_id' => $priceList->id,
                        'price' => 95.50,
                        'currency' => 'USD',
                        'is_active' => true,
                    ],
                ],
            ]);

        $this->assertSame(500, $response->getStatusCode());

        $countAfter = ProductPrice::query()
            ->where('product_id', $product->id)
            ->count();

        $this->assertSame($countBefore, $countAfter, 'ProductPrice NO debe haberse creado (rollback)');
    }

    public function test_exchange_rate_type_update_rolls_back_when_outbox_fails(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda ERT', 'slug' => 'tienda-ert']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin', ['currency.view', 'currency.manage']);

        $rateType = $this->useTenant($tenant, function (): ExchangeRateType {
            return ExchangeRateType::create([
                'code' => 'BCV',
                'name' => 'Tasa Original',
                'is_default' => true,
                'is_active' => true,
            ]);
        });

        $this->mock(SyncOutboxService::class, function ($mock): void {
            $mock->shouldReceive('record')
                ->andThrow(new \RuntimeException('Outbox unavailable'));
        });

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/currency/rate-types/{$rateType->id}", [
                'code' => 'BCV',
                'name' => 'Tasa Modificada',
                'is_default' => true,
                'is_active' => true,
            ]);

        $this->assertSame(500, $response->getStatusCode());

        $rateType->refresh();
        $this->assertSame('Tasa Original', $rateType->name, 'Nombre NO debe haberse modificado (rollback)');
    }

    public function test_exchange_rate_activate_rolls_back_when_outbox_fails(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda ER', 'slug' => 'tienda-er']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin', ['currency.view', 'currency.manage']);

        [$rateType, $rate] = $this->useTenant($tenant, function (): array {
            $rateType = ExchangeRateType::create([
                'code' => 'BCV',
                'name' => 'Tasa BCV',
                'is_default' => true,
                'is_active' => true,
            ]);
            $rate = \App\Modules\Currency\Models\ExchangeRate::create([
                'tenant_id' => $rateType->tenant_id,
                'exchange_rate_type_id' => $rateType->id,
                'base_currency' => 'USD',
                'quote_currency' => 'VES',
                'rate' => 500,
                'effective_at' => now(),
                'is_active' => false,
                'source' => 'Manual',
            ]);

            return [$rateType, $rate];
        });

        $this->mock(SyncOutboxService::class, function ($mock): void {
            $mock->shouldReceive('record')
                ->andThrow(new \RuntimeException('Outbox unavailable'));
        });

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/currency/rates/{$rate->id}/activate");

        $this->assertSame(500, $response->getStatusCode());

        $rate->refresh();
        $this->assertFalse((bool) $rate->is_active, 'is_active NO debe haberse activado (rollback)');
    }

    public function test_happy_path_customer_store_creates_both_business_and_outbox(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Happy', 'slug' => 'tienda-happy']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Admin', ['customers.view', 'customers.create']);

        DB::table('sync_nodes')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'local-happy-1',
            'name' => 'Local Happy',
            'type' => 'local',
            'status' => 'active',
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/customers', [
                'name' => 'Cliente Happy Path',
                'document_type' => 'V',
                'document_number' => '99999999',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'document_number' => '99999999',
            'name' => 'Cliente Happy Path',
        ]);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'customer.created',
            'aggregate_type' => 'customer',
        ]);
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant, function () use ($user, $roleName, $permissions): void {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
            $user->assignRole($role);
        });
    }

    private function useTenant(Tenant $tenant, \Closure $callback): mixed
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        return $callback();
    }
}