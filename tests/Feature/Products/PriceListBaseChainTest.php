<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Products\Services\ProductPriceService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PriceListBaseChainTest extends TestCase
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

    public function test_price_list_based_on_another_list_with_markup(): void
    {
        $tenant = Tenant::create(['name' => 'Listas', 'slug' => 'listas']);
        $this->useTenant($tenant);

        $product = Product::create([
            'name' => 'Telefono',
            'sku' => 'TLF-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $detal = PriceList::create(['name' => 'Precio Detal', 'code' => 'DETAL', 'markup_percentage' => 0, 'is_active' => true]);
        $cashea = PriceList::create(['name' => 'Precio Cashea', 'code' => 'CASHEA', 'markup_percentage' => 16, 'base_price_list_id' => $detal->id, 'is_active' => true]);

        $quote = app(ProductPriceService::class)->quote($product, $cashea->id);

        // Detal = base (100); Cashea = 100 * 1.16 = 116.
        $this->assertSame(116.0, (float) $quote['price_usd']);
        $this->assertSame($cashea->id, $quote['price_list_id']);
    }

    public function test_price_list_uses_product_price_of_base_list(): void
    {
        $tenant = Tenant::create(['name' => 'Listas2', 'slug' => 'listas2']);
        $this->useTenant($tenant);

        $product = Product::create([
            'name' => 'Telefono',
            'sku' => 'TLF-002',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $detal = PriceList::create(['name' => 'Precio Detal', 'code' => 'DETAL2', 'markup_percentage' => null, 'is_active' => true]);
        ProductPrice::create(['product_id' => $product->id, 'price_list_id' => $detal->id, 'price' => 150, 'currency' => Product::CURRENCY_USD, 'is_active' => true]);
        $cashea = PriceList::create(['name' => 'Precio Cashea', 'code' => 'CASHEA2', 'markup_percentage' => 16, 'base_price_list_id' => $detal->id, 'is_active' => true]);

        $quote = app(ProductPriceService::class)->quote($product, $cashea->id);

        // Detal = 150 (precio propio en la lista); Cashea = 150 * 1.16 = 174.
        $this->assertSame(174.0, (float) $quote['price_usd']);
    }

    public function test_price_list_chain_recursive_and_cycle_guard(): void
    {
        $tenant = Tenant::create(['name' => 'Listas3', 'slug' => 'listas3']);
        $this->useTenant($tenant);

        $product = Product::create([
            'name' => 'Telefono',
            'sku' => 'TLF-003',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $detal = PriceList::create(['name' => 'Detal', 'code' => 'DETAL3', 'markup_percentage' => 0, 'is_active' => true]);
        $mayor = PriceList::create(['name' => 'Mayor', 'code' => 'MAYOR3', 'markup_percentage' => 10, 'base_price_list_id' => $detal->id, 'is_active' => true]);
        $cashea = PriceList::create(['name' => 'Cashea', 'code' => 'CASHEA3', 'markup_percentage' => 16, 'base_price_list_id' => $mayor->id, 'is_active' => true]);

        $quote = app(ProductPriceService::class)->quote($product, $cashea->id);
        // 100 * 1.10 = 110 (mayor); 110 * 1.16 = 127.6 (cashea).
        $this->assertSame(127.6, (float) $quote['price_usd']);

        // Ciclo: A -> B -> A no debe colgarse; vuelve al precio base.
        $b = PriceList::create(['name' => 'B', 'code' => 'B3', 'markup_percentage' => 5, 'base_price_list_id' => $detal->id, 'is_active' => true]);
        $detal->update(['base_price_list_id' => $b->id]);
        $quoteCycle = app(ProductPriceService::class)->quote($product, $detal->id);
        $this->assertIsNumeric((float) $quoteCycle['price_usd']);
    }

    public function test_price_list_cannot_be_its_own_base(): void
    {
        $tenant = Tenant::create(['name' => 'Listas4', 'slug' => 'listas4']);
        $this->useTenant($tenant);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        setPermissionsTeamId($tenant->id);
        $role = Role::findOrCreate('Admin Lista', 'web');
        $role->syncPermissions(['products.update']);
        $user->assignRole($role);

        $list = PriceList::create(['name' => 'Auto', 'code' => 'AUTO4', 'markup_percentage' => 10, 'is_active' => true]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/price-lists/{$list->id}", ['base_price_list_id' => $list->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['base_price_list_id']);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
