<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Models\BulkPriceAdjustment;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Modules\Products\Models\ProductPackaging;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Products\Services\BulkPriceAdjustmentService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El cambio masivo de precios deja historial y viaja a los demas nodos, y las
 * etiquetas de anaquel se arman con el precio vigente en USD y Bs.
 */
class PriceLabelsAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Product $malta;

    private Product $harina;

    public function test_bulk_base_price_adjustment_is_audited_synced_and_shown_in_history(): void
    {
        $adjustment = $this->bulk(['product_ids' => [$this->malta->id]], ['target' => 'base_price', 'adjustment_type' => BulkPriceAdjustment::TYPE_PERCENTAGE_INCREASE, 'adjustment_value' => 20]);

        $this->assertEqualsWithDelta(1.44, (float) $this->malta->refresh()->base_price, 0.0001);
        $audit = ProductAudit::query()->where('product_id', $this->malta->id)->sole();
        $this->assertEqualsWithDelta(1.2, (float) $audit->changes['before']['base_price'], 0.0001);
        $this->assertEqualsWithDelta(1.44, (float) $audit->changes['after']['base_price'], 0.0001);
        $this->assertSame($this->user->id, $audit->created_by);
        $payload = json_decode((string) DB::table('sync_outbox')->where('event_type', 'product.updated')->where('aggregate_id', $this->malta->id)->value('payload'), true);
        $this->assertNotNull($payload, 'El nuevo precio viaja a los demas nodos.');
        $this->assertEqualsWithDelta(1.44, (float) ($payload['product']['base_price'] ?? $payload['base_price']), 0.0001);
        $this->assertFalse(DB::table('sync_outbox')->where('event_type', 'product.updated')->where('aggregate_id', $this->harina->id)->exists());

        $this->getJson("/api/products/{$this->malta->id}/price-history")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'base_price')
            ->assertJsonPath('data.0.price_list_name', 'Precio base')
            ->assertJsonPath('data.0.before.price', 1.2)
            ->assertJsonPath('data.0.after.price', 1.44);

        app(TenantManager::class)->set($this->tenant);
        app(BulkPriceAdjustmentService::class)->rollback($adjustment, $this->user);

        $this->assertEqualsWithDelta(1.2, (float) $this->malta->refresh()->base_price, 0.0001);
        $this->assertSame(2, ProductAudit::query()->where('product_id', $this->malta->id)->count(), 'El reverso tambien queda en el historial.');
        $this->assertSame(2, DB::table('sync_outbox')->where('event_type', 'product.updated')->where('aggregate_id', $this->malta->id)->count(), 'El reverso tambien viaja.');
    }

    public function test_bulk_price_list_adjustment_is_audited_and_synced(): void
    {
        $list = PriceList::create(['name' => 'Mayor', 'code' => 'MAYOR', 'is_active' => true]);
        $price = ProductPrice::create(['product_id' => $this->malta->id, 'price_list_id' => $list->id, 'price' => 1, 'currency' => Product::CURRENCY_USD, 'is_active' => true]);
        DB::table('sync_outbox')->delete();

        $adjustment = $this->bulk(['product_ids' => [$this->malta->id, $this->harina->id]], ['target' => 'price_list', 'price_list_id' => $list->id, 'adjustment_type' => BulkPriceAdjustment::TYPE_FIXED_AMOUNT, 'adjustment_value' => 0.5]);

        $this->assertEqualsWithDelta(1.5, (float) $price->refresh()->price, 0.0001);
        $this->assertSame(1, DB::table('sync_outbox')->where('event_type', 'product_price.updated')->where('aggregate_id', $price->id)->count());
        $created = ProductPrice::query()->where('product_id', $this->harina->id)->where('price_list_id', $list->id)->sole();
        $this->assertSame(1, DB::table('sync_outbox')->where('event_type', 'product_price.created')->where('aggregate_id', $created->id)->count(), 'Un precio nuevo de lista se sincroniza como creado.');

        $this->getJson("/api/products/{$this->malta->id}/price-history")
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'price_list')
            ->assertJsonPath('data.0.price_list_name', 'Mayor')
            ->assertJsonPath('data.0.before.price', 1)
            ->assertJsonPath('data.0.after.price', 1.5);

        app(TenantManager::class)->set($this->tenant);
        app(BulkPriceAdjustmentService::class)->rollback($adjustment, $this->user);

        $this->assertEqualsWithDelta(1.0, (float) $price->refresh()->price, 0.0001);
        $this->assertSame(2, DB::table('sync_outbox')->where('event_type', 'product_price.updated')->where('aggregate_id', $price->id)->count());
        $this->assertSame(2, ProductAudit::query()->where('product_id', $this->malta->id)->count());
    }

    public function test_labels_for_selected_products_show_usd_and_bolivares(): void
    {
        $this->getJson('/api/product-labels?product_ids[]='.$this->malta->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.labels')
            ->assertJsonPath('data.labels.0.product_id', $this->malta->id)
            ->assertJsonPath('data.labels.0.name', 'Malta 355ml')
            ->assertJsonPath('data.labels.0.sku', 'MALTA')
            ->assertJsonPath('data.labels.0.barcode', '7590001')
            ->assertJsonPath('data.labels.0.price_usd', 1.2)
            ->assertJsonPath('data.labels.0.price_ves', 120)
            ->assertJsonPath('data.labels.0.exchange_rate', 100)
            ->assertJsonPath('data.labels.0.exchange_rate_type_code', 'BCV')
            ->assertJsonPath('data.skipped', []);
    }

    public function test_labels_use_the_requested_price_list(): void
    {
        $list = PriceList::create(['name' => 'Mayor', 'code' => 'MAYOR', 'is_active' => true]);
        ProductPrice::create(['product_id' => $this->malta->id, 'price_list_id' => $list->id, 'price' => 1, 'currency' => Product::CURRENCY_USD, 'is_active' => true]);

        $this->getJson("/api/product-labels?price_list_id={$list->id}&product_ids[]={$this->malta->id}")
            ->assertOk()
            ->assertJsonPath('data.labels.0.price_usd', 1)
            ->assertJsonPath('data.labels.0.price_list_name', 'Mayor');
    }

    public function test_labels_include_packagings_with_their_own_price(): void
    {
        ProductPackaging::create(['product_id' => $this->malta->id, 'name' => 'Caja x24', 'factor' => 24, 'barcode' => '7590024', 'price_usd' => 24]);
        ProductPackaging::create(['product_id' => $this->malta->id, 'name' => 'Six pack', 'factor' => 6, 'price_usd' => null]);
        ProductPackaging::create(['product_id' => $this->malta->id, 'name' => 'Inactiva', 'factor' => 12, 'price_usd' => 10, 'is_active' => false]);

        $this->getJson('/api/product-labels?product_ids[]='.$this->malta->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.labels.0.packagings')
            ->assertJsonPath('data.labels.0.packagings.0.name', 'Caja x24')
            ->assertJsonPath('data.labels.0.packagings.0.barcode', '7590024')
            ->assertJsonPath('data.labels.0.packagings.0.price_usd', 24)
            ->assertJsonPath('data.labels.0.packagings.0.price_ves', 2400);
    }

    public function test_labels_for_products_whose_price_changed_since_a_date(): void
    {
        $this->travelTo(now()->subDays(10));
        $this->bulk(['product_ids' => [$this->harina->id]], ['target' => 'base_price', 'adjustment_type' => BulkPriceAdjustment::TYPE_FIXED_AMOUNT, 'adjustment_value' => 1]);
        $this->travelBack();
        $this->bulk(['product_ids' => [$this->malta->id]], ['target' => 'base_price', 'adjustment_type' => BulkPriceAdjustment::TYPE_FIXED_AMOUNT, 'adjustment_value' => 1]);

        $this->getJson('/api/product-labels?changed_since='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data.labels')
            ->assertJsonPath('data.labels.0.product_id', $this->malta->id);

        $this->getJson('/api/product-labels?changed_since='.now()->subDays(15)->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'data.labels');
    }

    public function test_labels_for_the_products_of_a_bulk_adjustment(): void
    {
        $adjustment = $this->bulk(['product_ids' => [$this->harina->id]], ['target' => 'base_price', 'adjustment_type' => BulkPriceAdjustment::TYPE_FIXED_AMOUNT, 'adjustment_value' => 1]);

        $this->getJson("/api/product-labels?bulk_adjustment_id={$adjustment->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.labels')
            ->assertJsonPath('data.labels.0.product_id', $this->harina->id)
            ->assertJsonPath('data.labels.0.price_usd', 3.5);
    }

    public function test_products_without_a_price_are_skipped_with_a_reason(): void
    {
        $free = Product::create(['name' => 'Sin precio', 'sku' => 'NOPRICE', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => null, 'sale_currency' => Product::CURRENCY_USD]);

        $this->getJson("/api/product-labels?product_ids[]={$free->id}&product_ids[]={$this->malta->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.labels')
            ->assertJsonPath('data.skipped.0.product_id', $free->id)
            ->assertJsonPath('data.skipped.0.name', 'Sin precio');
    }

    public function test_labels_require_a_selection_and_the_products_view_permission(): void
    {
        $this->getJson('/api/product-labels')->assertUnprocessable();

        $clerk = User::factory()->create();
        $clerk->tenants()->attach($this->tenant, ['status' => 'active']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($clerk)->getJson('/api/product-labels?product_ids[]='.$this->malta->id)->assertForbidden();
    }

    public function test_labels_never_include_products_of_another_company(): void
    {
        $other = Tenant::create(['name' => 'Otra', 'slug' => 'otra-'.uniqid()]);
        app(TenantManager::class)->set($other);
        $foreign = Product::create(['name' => 'Ajeno', 'sku' => 'AJENO', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 5, 'sale_currency' => Product::CURRENCY_USD]);
        app(TenantManager::class)->set($this->tenant);

        $this->getJson("/api/product-labels?product_ids[]={$foreign->id}&product_ids[]={$this->malta->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.labels')
            ->assertJsonPath('data.labels.0.product_id', $this->malta->id);
    }

    private function bulk(array $filters, array $rules): BulkPriceAdjustment
    {
        app(TenantManager::class)->set($this->tenant);

        return app(BulkPriceAdjustmentService::class)->apply($this->user, $filters, $rules + ['rounding' => 'none']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Bodegon', 'slug' => 'bodegon-'.uniqid()]);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $this->user->assignRole($role);
        $this->actingAs($this->user)->withHeader('X-Tenant', $this->tenant->slug);

        $rateType = ExchangeRateType::create(['name' => 'BCV', 'code' => 'BCV', 'is_default' => true]);
        ExchangeRate::create(['exchange_rate_type_id' => $rateType->id, 'rate' => 100, 'effective_at' => now()->subMonth(), 'is_active' => true]);
        $this->malta = Product::create(['name' => 'Malta 355ml', 'sku' => 'MALTA', 'barcode' => '7590001', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 1.2, 'average_cost' => 0.8, 'sale_currency' => Product::CURRENCY_USD]);
        $this->harina = Product::create(['name' => 'Harina 1kg', 'sku' => 'HARINA', 'barcode' => '7590002', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 2.5, 'average_cost' => 1.5, 'sale_currency' => Product::CURRENCY_USD]);
        DB::table('sync_outbox')->delete();
    }
}
