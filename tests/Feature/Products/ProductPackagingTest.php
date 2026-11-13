<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPackaging;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Presentaciones (caja, bulto, paquete): se compran por presentacion y se
 * convierten a unidades, y se venden con su precio propio si lo tienen.
 */
class ProductPackagingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Warehouse $warehouse;

    private Product $product;

    public function test_packagings_can_be_created_listed_updated_and_deleted(): void
    {
        $created = $this->postJson("/api/products/{$this->product->id}/packagings", [
            'name' => 'Caja x24',
            'factor' => 24,
            'barcode' => '7590000000240',
            'price_usd' => 24,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Caja x24')
            ->assertJsonPath('data.factor', 24)
            ->assertJsonPath('data.price_usd', 24)
            ->assertJsonPath('data.unit_price_usd', 1);

        $id = $created->json('data.id');
        $this->getJson("/api/products/{$this->product->id}/packagings")->assertOk()->assertJsonPath('data.0.barcode', '7590000000240');

        $this->patchJson("/api/product-packagings/{$id}", ['price_usd' => 26.4])->assertOk()->assertJsonPath('data.price_usd', 26.4);
        $this->deleteJson("/api/product-packagings/{$id}")->assertNoContent();
        $this->assertSame(0, ProductPackaging::query()->count());
    }

    public function test_packaging_validation_rejects_bad_factor_and_duplicate_barcodes(): void
    {
        $this->postJson("/api/products/{$this->product->id}/packagings", ['name' => 'Caja', 'factor' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('factor');

        $this->postJson("/api/products/{$this->product->id}/packagings", ['name' => 'Caja', 'factor' => 12, 'barcode' => 'MALTA-UNIDAD'])
            ->assertUnprocessable()->assertJsonValidationErrors('barcode');

        $this->packaging(['barcode' => 'CAJA-24']);
        $this->postJson("/api/products/{$this->product->id}/packagings", ['name' => 'Otra', 'factor' => 6, 'barcode' => 'CAJA-24'])
            ->assertUnprocessable()->assertJsonValidationErrors('barcode');
    }

    public function test_managing_packagings_requires_product_update_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->tenants()->attach($this->tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Consulta productos', 'web');
        $role->syncPermissions(['products.view']);
        $viewer->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer)->getJson("/api/products/{$this->product->id}/packagings")->assertOk();
        $this->actingAs($viewer)->postJson("/api/products/{$this->product->id}/packagings", ['name' => 'Caja', 'factor' => 24])->assertForbidden();
    }

    public function test_lookup_by_barcode_returns_the_product_and_its_packaging(): void
    {
        $packaging = $this->packaging();

        $this->getJson('/api/product-packagings/lookup?code=7590000000240')
            ->assertOk()
            ->assertJsonPath('data.packaging.id', $packaging->id)
            ->assertJsonPath('data.packaging.factor', 24)
            ->assertJsonPath('data.product.id', $this->product->id)
            ->assertJsonPath('data.product.sku', 'MALTA');

        $this->getJson('/api/product-packagings/lookup?code=NO-EXISTE')->assertNotFound();

        app(TenantManager::class)->set($this->tenant);
        $packaging->update(['is_active' => false]);
        $this->getJson('/api/product-packagings/lookup?code=7590000000240')->assertNotFound();
    }

    public function test_packagings_of_another_company_are_not_reachable(): void
    {
        $packaging = $this->packaging();
        $other = Tenant::create(['name' => 'Otra', 'slug' => 'otra-'.uniqid()]);
        app(TenantManager::class)->set($other);
        setPermissionsTeamId($other->id);
        $intruder = User::factory()->create();
        $intruder->tenants()->attach($other, ['status' => 'active']);
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $intruder->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($intruder)->withHeader('X-Tenant', $other->slug)->patchJson("/api/product-packagings/{$packaging->id}", ['price_usd' => 1])->assertNotFound();
        $this->actingAs($intruder)->withHeader('X-Tenant', $other->slug)->getJson('/api/product-packagings/lookup?code=7590000000240')->assertNotFound();
        $this->assertEqualsWithDelta(24.0, (float) $packaging->refresh()->price_usd, 0.0001);
    }

    public function test_buying_by_packaging_converts_to_units_and_unit_cost(): void
    {
        $packaging = $this->packaging();
        $supplier = Supplier::create(['name' => 'Cerveceria', 'document_type' => Supplier::DOCUMENT_J, 'document_number' => '700000007']);

        $purchase = app(PurchaseOrderService::class)->createDraft($this->user, [
            'supplier_id' => $supplier->id,
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'items' => [[
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $this->product->id,
                'product_packaging_id' => $packaging->id,
                'packaging_quantity' => 2,
                'packaging_cost' => 18,
            ]],
        ])->load('items');

        $item = $purchase->items->first();
        $this->assertEqualsWithDelta(48.0, (float) $item->quantity, 0.0001, '2 cajas x24 = 48 unidades.');
        $this->assertEqualsWithDelta(0.75, (float) $item->unit_cost, 0.0001, '$18 la caja / 24 = $0.75 la unidad.');
        $this->assertEqualsWithDelta(36.0, (float) $item->total_cost, 0.0001);
        $this->assertSame($packaging->id, $item->product_packaging_id);
        $this->assertEqualsWithDelta(2.0, (float) $item->packaging_quantity, 0.0001);
        $this->getJson("/api/purchases/{$purchase->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.packaging_quantity', 2)
            ->assertJsonPath('data.items.0.packaging_cost', 18)
            ->assertJsonPath('data.items.0.packaging.name', 'Caja x24');
        app(TenantManager::class)->set($this->tenant);

        app(PurchaseOrderService::class)->receive($purchase, $this->user);

        $this->assertSame(48.0, (float) StockBalance::query()->where('product_id', $this->product->id)->value('quantity_available'));
        $this->assertEqualsWithDelta(0.75, (float) $this->product->refresh()->last_purchase_cost, 0.0001);
    }

    public function test_selling_a_packaging_uses_its_own_price(): void
    {
        $packaging = $this->packaging();
        $this->stock(48);

        $sale = app(SaleService::class)->createDraft($this->user, [[
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'product_packaging_id' => $packaging->id,
            'quantity' => 24,
        ]])->load('items');

        $item = $sale->items->first();
        $this->assertEqualsWithDelta(24.0, (float) $item->quantity, 0.0001);
        $this->assertEqualsWithDelta(24.0, (float) $item->base_total_amount, 0.0001, 'Precio al mayor: $24 la caja, no 24 x $1.20.');
        $this->assertEqualsWithDelta(1.0, (float) $item->base_unit_price, 0.0001);
        $this->assertEqualsWithDelta(24.0, (float) $item->total_amount, 0.0001, 'El total en la moneda de venta tambien usa el precio de la caja.');
        $this->assertEqualsWithDelta(2400.0, (float) $sale->total_local_amount, 0.0001, 'El equivalente en Bs sigue la misma proporcion.');
        $this->assertSame($packaging->id, $item->product_packaging_id);
        $this->assertEqualsWithDelta(1.0, (float) $item->packaging_quantity, 0.0001);
    }

    public function test_a_packaging_without_own_price_sells_at_the_unit_price(): void
    {
        $packaging = $this->packaging(['price_usd' => null]);
        $this->stock(48);

        $sale = app(SaleService::class)->createDraft($this->user, [[
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'product_packaging_id' => $packaging->id,
            'quantity' => 24,
        ]])->load('items');

        $this->assertEqualsWithDelta(28.8, (float) $sale->items->first()->base_total_amount, 0.0001);
    }

    public function test_a_packaging_of_another_product_is_rejected(): void
    {
        $other = Product::create(['name' => 'Refresco', 'sku' => 'REF', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 1, 'sale_currency' => Product::CURRENCY_USD]);
        $packaging = ProductPackaging::create(['product_id' => $other->id, 'name' => 'Caja x12', 'factor' => 12, 'price_usd' => 10]);
        $this->stock(48);

        try {
            app(SaleService::class)->createDraft($this->user, [[
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $this->product->id,
                'product_packaging_id' => $packaging->id,
                'quantity' => 12,
            ]]);
            $this->fail('Se esperaba rechazar la presentacion de otro producto.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
    }

    public function test_buying_with_a_packaging_of_another_product_is_rejected(): void
    {
        $other = Product::create(['name' => 'Refresco', 'sku' => 'REF', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 1, 'sale_currency' => Product::CURRENCY_USD]);
        $packaging = ProductPackaging::create(['product_id' => $other->id, 'name' => 'Caja x12', 'factor' => 12, 'price_usd' => 10]);
        $supplier = Supplier::create(['name' => 'Cerveceria', 'document_type' => Supplier::DOCUMENT_J, 'document_number' => '700000008']);

        try {
            app(PurchaseOrderService::class)->createDraft($this->user, [
                'supplier_id' => $supplier->id,
                'purchase_currency' => PurchaseOrder::CURRENCY_USD,
                'items' => [[
                    'warehouse_id' => $this->warehouse->id,
                    'product_id' => $this->product->id,
                    'product_packaging_id' => $packaging->id,
                    'packaging_quantity' => 1,
                    'packaging_cost' => 9,
                ]],
            ]);
            $this->fail('Se esperaba rechazar la presentacion de otro producto.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    public function test_packagings_are_synced_by_product_and_name(): void
    {
        $this->packaging();

        $payload = json_decode((string) DB::table('sync_outbox')->where('event_type', 'product_packaging.upserted')->value('payload'), true);
        $this->assertSame('MALTA', $payload['sku']);
        $this->assertSame('Caja x24', $payload['name']);
        $this->assertSame('7590000000240', $payload['barcode']);

        DB::table('product_packagings')->delete();
        $payload['price_usd'] = '25.0000';
        foreach ([1, 2] as $ignored) {
            DB::table('sync_inbox')->insert([
                'tenant_id' => $this->tenant->id,
                'event_uuid' => (string) Str::uuid(),
                'event_type' => 'product_packaging.upserted',
                'aggregate_type' => 'product_packaging',
                'aggregate_id' => 1,
                'payload_hash' => hash('sha256', json_encode($payload)),
                'payload' => json_encode($payload),
                'status' => 'received',
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        app(SyncEventApplier::class)->applyPending($this->tenant, 10);

        $replicated = ProductPackaging::query()->sole();
        $this->assertSame($this->product->id, $replicated->product_id);
        $this->assertEqualsWithDelta(24.0, (float) $replicated->factor, 0.0001);
        $this->assertEqualsWithDelta(25.0, (float) $replicated->price_usd, 0.0001);
        $this->assertNotNull($replicated->updated_at);
    }

    private function packaging(array $overrides = []): ProductPackaging
    {
        app(TenantManager::class)->set($this->tenant);

        return ProductPackaging::create(array_merge([
            'product_id' => $this->product->id,
            'name' => 'Caja x24',
            'factor' => 24,
            'barcode' => '7590000000240',
            'price_usd' => 24,
        ], $overrides));
    }

    private function stock(float $quantity): void
    {
        app(InventoryMovementService::class)->purchase(warehouse: $this->warehouse, product: $this->product, quantity: $quantity, unitCost: 0.75, createdBy: $this->user);
    }

    protected function setUp(): void
    {
        parent::setUp();

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
        ExchangeRate::create(['exchange_rate_type_id' => $rateType->id, 'rate' => 100, 'effective_at' => now()->subMinute(), 'is_active' => true]);
        $branch = Branch::create(['name' => 'Principal', 'code' => 'PRI']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'ALM']);
        $this->product = Product::create(['name' => 'Malta', 'sku' => 'MALTA', 'barcode' => 'MALTA-UNIDAD', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 1.2, 'sale_currency' => Product::CURRENCY_USD]);
    }
}
