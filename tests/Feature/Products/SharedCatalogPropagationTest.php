<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Services\SharedCatalogPropagationService;
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

class SharedCatalogPropagationTest extends TestCase
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

    public function test_creating_master_product_propagates_copy_to_existing_spinoffs(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff('danubio-soledad');

        $this->useTenant($group);

        $product = Product::create([
            'name' => 'iPhone 13',
            'sku' => 'IPHONE-13',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        $product = $product->fresh();

        app(SharedCatalogPropagationService::class)->propagateMaster($product);

        $this->assertTrue($product->isCatalogMaster());

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $product->id)
            ->first();

        $this->assertNotNull($copy);
        $this->assertSame($spinoff->id, $copy->tenant_id);
        $this->assertSame($product->id, $copy->catalog_product_id);
        $this->assertFalse($copy->isCatalogMaster());
        $this->assertSame('iPhone 13', $copy->name);
        $this->assertSame('IPHONE-13', $copy->sku);
        $this->assertTrue((bool) $copy->is_catalog_active);
    }

    public function test_updating_master_product_syncs_master_fields_to_copies(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff('danubio-soledad');

        $this->useTenant($group);

        $product = Product::create([
            'name' => 'iPhone 13',
            'sku' => 'IPHONE-13',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        $product = $product->fresh();

        app(SharedCatalogPropagationService::class)->propagateMaster($product);

        $product->update([
            'name' => 'iPhone 13 Pro',
            'base_price' => 700,
        ]);

        $product = $product->fresh();

        app(SharedCatalogPropagationService::class)->syncMasterFieldsToCopies($product);

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $product->id)
            ->first();

        $this->assertSame('iPhone 13 Pro', $copy->name);
        $this->assertSame('700.0000', $copy->base_price);
    }

    public function test_deactivating_master_product_marks_copies_inactive(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff('danubio-soledad');

        $this->useTenant($group);

        $product = Product::create([
            'name' => 'iPhone 13',
            'sku' => 'IPHONE-13',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        $product = $product->fresh();

        app(SharedCatalogPropagationService::class)->propagateMaster($product);

        $product->update(['is_active' => false, 'is_catalog_active' => false]);

        $product = $product->fresh();

        app(SharedCatalogPropagationService::class)->deactivateCopiesForMaster($product);

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $product->id)
            ->first();

        $this->assertFalse((bool) $copy->is_active);
        $this->assertFalse((bool) $copy->is_catalog_active);
    }

    public function test_new_spinoff_receives_existing_master_catalog_via_bootstrap(): void
    {
        $group = Tenant::create([
            'name' => 'Danubio',
            'slug' => 'danubio',
            'is_group' => true,
        ]);

        $firstSpinoff = Tenant::create([
            'name' => 'Danubio Valencia',
            'slug' => 'danubio-valencia',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);

        $this->useTenant($group);

        Product::create([
            'name' => 'Samsung S23',
            'sku' => 'SAM-S23',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 400,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        $owner = $this->makeGroupOwner($group);
        $this->actingAs($owner)->postJson("/api/tenant-groups/{$group->id}/tenants", [
            'name' => 'Danubio Soledad',
            'slug' => 'danubio-soledad',
            'admin' => [
                'name' => 'Admin Soledad',
                'email' => 'admin.soledad@danubio.test',
                'password' => 'secret123',
            ],
        ])->assertCreated();

        $secondSpinoff = Tenant::where('slug', 'danubio-soledad')->firstOrFail();
        $this->assertTrue($secondSpinoff->isSpinoff());

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $secondSpinoff->id)
            ->where('catalog_product_id', function ($sub) use ($group): void {
                $sub->select('id')
                    ->from('products')
                    ->where('tenant_id', $group->id)
                    ->where('is_catalog_master', true)
                    ->where('sku', 'SAM-S23');
            })
            ->first();

        $this->assertNotNull($copy);
        $this->assertSame('Samsung S23', $copy->name);
    }

    public function test_spinoff_can_register_entry_using_its_local_copy_of_shared_product(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff('danubio-soledad');

        $this->useTenant($group);

        $master = Product::create([
            'name' => 'Xiaomi Note 12',
            'sku' => 'XIAOMI-N12',
            'tracking_type' => Product::TRACKING_SERIALIZED,
            'base_price' => 250,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        $master = $master->fresh();

        app(SharedCatalogPropagationService::class)->propagateMaster($master);

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $master->id)
            ->firstOrFail();

        $spinoffWarehouse = $this->createWarehouseFor($spinoff, 'WH-DAN-SOLEDAD');
        $spinoffUser = $this->grantPermissions($spinoff, 'spinoff@danubio.test', [
            'product_entries.create',
            'product_entries.view',
        ]);

        $imeis = collect(range(1, 3))->map(fn (int $i): array => [
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '86090010000000'.$i,
        ])->all();

        $this->useTenant($spinoff);

        $this->actingAs($spinoffUser)
            ->withHeader('X-Tenant', $spinoff->slug)
            ->postJson('/api/product-entries', [
                'reason' => 'Entrada desde spinoff',
                'reference' => 'GUIA-SPIN-001',
                'items' => [[
                    'warehouse_id' => $spinoffWarehouse->id,
                    'product_id' => $copy->id,
                    'quantity' => 3,
                    'unit_cost' => 200,
                    'serial_units' => $imeis,
                ]],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('product_entries', [
            'tenant_id' => $spinoff->id,
            'reference' => 'GUIA-SPIN-001',
        ]);

        $this->assertDatabaseHas('product_entry_items', [
            'tenant_id' => $spinoff->id,
            'product_id' => $copy->id,
            'warehouse_id' => $spinoffWarehouse->id,
        ]);

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $spinoff->id,
            'warehouse_id' => $spinoffWarehouse->id,
            'product_id' => $copy->id,
            'quantity_available' => '3.0000',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $spinoff->id,
            'warehouse_id' => $spinoffWarehouse->id,
            'product_id' => $copy->id,
            'type' => 'purchase',
            'quantity' => '3.0000',
            'reference_type' => ProductEntry::class,
        ]);

        $this->assertSame(3, ProductUnit::query()
            ->where('tenant_id', $spinoff->id)
            ->where('product_id', $copy->id)
            ->count());

        $this->assertSame(0, StockMovement::query()
            ->where('tenant_id', $spinoff->id)
            ->where('product_id', $master->id)
            ->count());

        $this->assertSame(0, StockBalance::query()
            ->where('tenant_id', $spinoff->id)
            ->where('product_id', $master->id)
            ->count());
    }

    public function test_master_product_variants_propagate_to_spinoff_copy(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff('danubio-soledad');

        $this->useTenant($group);

        $product = Product::create([
            'name' => 'iPhone 13',
            'sku' => 'IPHONE-VAR-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        $product->variants()->create([
            'color' => 'Azul',
            'color_hex' => '#2563EB',
            'sku_variant' => 'IPHONE-VAR-AZUL',
            'is_active' => true,
            'position' => 1,
        ]);
        $product->variants()->create([
            'color' => 'Negro',
            'color_hex' => '#000000',
            'sku_variant' => 'IPHONE-VAR-NEGRO',
            'is_active' => true,
            'position' => 2,
        ]);

        app(SharedCatalogPropagationService::class)->propagateMaster($product);
        app(SharedCatalogPropagationService::class)->propagateProductVariants($product);

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $product->id)
            ->first();

        $this->assertNotNull($copy);

        $variants = DB::table('product_variants')
            ->where('tenant_id', $spinoff->id)
            ->where('product_id', $copy->id)
            ->whereNotNull('color')
            ->orderBy('position')
            ->get();

        $this->assertCount(2, $variants);
        $this->assertSame(['Azul', 'Negro'], $variants->pluck('color')->all());
        $this->assertContains('IPHONE-VAR-AZUL', $variants->pluck('sku_variant')->all());
        $this->assertContains('IPHONE-VAR-NEGRO', $variants->pluck('sku_variant')->all());
    }

    public function test_master_product_variant_delete_cascades_to_spinoff_copy(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff('danubio-soledad');

        $this->useTenant($group);

        $product = Product::create([
            'name' => 'iPhone 13',
            'sku' => 'IPHONE-VAR-DEL-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        $variant = $product->variants()->create([
            'color' => 'Verde',
            'color_hex' => '#16A34A',
            'sku_variant' => 'IPHONE-VAR-VERDE',
            'is_active' => true,
            'position' => 1,
        ]);

        app(SharedCatalogPropagationService::class)->propagateMaster($product);
        app(SharedCatalogPropagationService::class)->propagateProductVariants($product);

        $copyVariant = DB::table('product_variants')
            ->where('tenant_id', $spinoff->id)
            ->where('sku_variant', 'IPHONE-VAR-VERDE')
            ->first();

        $this->assertNotNull($copyVariant);

        app(SharedCatalogPropagationService::class)->propagateProductVariantDeleted($variant);

        $after = DB::table('product_variants')
            ->where('tenant_id', $spinoff->id)
            ->where('sku_variant', 'IPHONE-VAR-VERDE')
            ->first();

        $this->assertNull($after, 'La variante debe eliminarse en el spinoff.');
    }

    public function test_master_variant_emits_sync_event_in_spinoff_outbox(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff('danubio-soledad');

        $this->useTenant($group);

        $product = Product::create([
            'name' => 'iPhone 13',
            'sku' => 'IPHONE-VAR-OUTBOX-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        $product->variants()->create([
            'color' => 'Rojo',
            'color_hex' => '#EF4444',
            'sku_variant' => 'IPHONE-VAR-ROJO',
            'is_active' => true,
            'position' => 1,
        ]);

        app(SharedCatalogPropagationService::class)->propagateMaster($product);
        app(SharedCatalogPropagationService::class)->propagateProductVariants($product);

        $event = DB::table('sync_outbox')
            ->where('tenant_id', $spinoff->id)
            ->where('event_type', 'product_variant.created')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event, 'Debe emitirse product_variant.created en el outbox del spinoff.');
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame('IPHONE-VAR-OUTBOX-001', $payload['product_sku'] ?? null);
        $this->assertSame('Rojo', $payload['color'] ?? null);
    }

    public function test_master_product_image_propagates_to_spinoff_copy(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff('danubio-soledad');

        $this->useTenant($group);

        $product = Product::create([
            'name' => 'iPhone 13',
            'sku' => 'IPHONE-IMG-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        app(SharedCatalogPropagationService::class)->propagateMaster($product);

        // Subir una imagen al master.
        $image = ProductImage::create([
            'tenant_id' => $group->id,
            'product_id' => $product->id,
            'uuid' => '22222222-2222-4222-8222-222222222222',
            'storage_path' => 'products/1/2026/08/master-image.webp',
            'cloud_storage_path' => 'products/1/2026/08/master-image.webp',
            'mime' => 'image/webp',
            'size' => 1234,
            'width' => 800,
            'height' => 600,
            'sha256' => str_repeat('d', 64),
            'sort' => 0,
            'is_primary' => true,
        ]);

        app(SharedCatalogPropagationService::class)->propagateProductImages($product);

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $product->id)
            ->first();

        $this->assertNotNull($copy);

        $copyImage = ProductImage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('product_id', $copy->id)
            ->first();

        $this->assertNotNull($copyImage, 'La imagen del master debe propagarse a la copia del spinoff.');
        $this->assertSame($image->uuid, $copyImage->uuid);
        $this->assertSame('products/1/2026/08/master-image.webp', $copyImage->cloud_storage_path);
        $this->assertSame($image->sha256, $copyImage->sha256);
    }

    public function test_master_product_image_delete_cascades_to_spinoff_copies(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff('danubio-soledad');

        $this->useTenant($group);

        $product = Product::create([
            'name' => 'iPhone 13',
            'sku' => 'IPHONE-IMG-DEL-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        app(SharedCatalogPropagationService::class)->propagateMaster($product);

        $image = ProductImage::create([
            'tenant_id' => $group->id,
            'product_id' => $product->id,
            'uuid' => '33333333-3333-4333-8333-333333333333',
            'storage_path' => 'products/1/2026/08/master-del.webp',
            'cloud_storage_path' => 'products/1/2026/08/master-del.webp',
            'mime' => 'image/webp',
            'size' => 1234,
            'width' => 800,
            'height' => 600,
            'sha256' => str_repeat('e', 64),
            'sort' => 0,
            'is_primary' => true,
        ]);

        app(SharedCatalogPropagationService::class)->propagateProductImages($product);

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $product->id)
            ->first();

        $copyImage = ProductImage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('uuid', $image->uuid)
            ->first();

        $this->assertNotNull($copyImage);

        // Soft-delete en el master.
        app(SharedCatalogPropagationService::class)->propagateProductImageDeleted($image);

        $deletedCopy = ProductImage::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('tenant_id', $spinoff->id)
            ->where('uuid', $image->uuid)
            ->first();

        $this->assertNotNull($deletedCopy);
        $this->assertNotNull($deletedCopy->deleted_at, 'La copia en el spinoff debe marcarse como eliminada.');
    }

    public function test_image_upload_emits_sync_event_in_spinoff_outbox(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff('danubio-soledad');

        $this->useTenant($group);

        $product = Product::create([
            'name' => 'iPhone 13',
            'sku' => 'IPHONE-IMG-OUTBOX-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        app(SharedCatalogPropagationService::class)->propagateMaster($product);

        $image = ProductImage::create([
            'tenant_id' => $group->id,
            'product_id' => $product->id,
            'uuid' => '44444444-4444-4444-8444-444444444444',
            'storage_path' => 'products/1/2026/08/master-outbox.webp',
            'cloud_storage_path' => 'products/1/2026/08/master-outbox.webp',
            'mime' => 'image/webp',
            'size' => 1234,
            'width' => 800,
            'height' => 600,
            'sha256' => str_repeat('f', 64),
            'sort' => 0,
            'is_primary' => true,
        ]);

        app(SharedCatalogPropagationService::class)->propagateProductImages($product);

        $event = DB::table('sync_outbox')
            ->where('tenant_id', $spinoff->id)
            ->where('event_type', 'product.image.uploaded')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event, 'Debe emitirse product.image.uploaded en el outbox del spinoff.');
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame($image->uuid, $payload['uuid'] ?? null);
        $this->assertSame('IPHONE-IMG-OUTBOX-001', $payload['product_sku'] ?? null);
    }

    public function test_new_spinoff_receives_variants_and_images_from_masters(): void
    {
        [$group, $spinoff] = $this->createGroupWithSpinoff('danubio-nuevo');

        $this->useTenant($group);

        $product = Product::create([
            'name' => 'iPhone 13',
            'sku' => 'IPHONE-NEW-SPIN-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 500,
            'sale_currency' => Product::CURRENCY_USD,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
            'is_catalog_master' => true,
        ]);

        $product->variants()->create([
            'color' => 'Dorado',
            'color_hex' => '#D4AF37',
            'sku_variant' => 'IPHONE-NEW-SPIN-DORADO',
            'is_active' => true,
            'position' => 1,
        ]);

        ProductImage::create([
            'tenant_id' => $group->id,
            'product_id' => $product->id,
            'uuid' => '55555555-5555-4555-8555-555555555555',
            'storage_path' => 'products/1/2026/08/master-new-spin.webp',
            'cloud_storage_path' => 'products/1/2026/08/master-new-spin.webp',
            'mime' => 'image/webp',
            'size' => 1234,
            'width' => 800,
            'height' => 600,
            'sha256' => str_repeat('a1', 32),
            'sort' => 0,
            'is_primary' => true,
        ]);

        // El spinoff se crea DESPUES del producto: propagateAllToSpinoff
        // debe copiar producto + variantes + imagenes.
        $this->useTenant($group);
        app(SharedCatalogPropagationService::class)->propagateAllToSpinoff($group, $spinoff);

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $product->id)
            ->first();

        $this->assertNotNull($copy, 'El producto debe copiarse al spinoff nuevo.');

        $variant = DB::table('product_variants')
            ->where('tenant_id', $spinoff->id)
            ->where('product_id', $copy->id)
            ->where('sku_variant', 'IPHONE-NEW-SPIN-DORADO')
            ->first();

        $this->assertNotNull($variant, 'La variante del master debe copiarse al spinoff nuevo.');
        $this->assertSame('Dorado', $variant->color);

        $image = ProductImage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('uuid', '55555555-5555-4555-8555-555555555555')
            ->first();

        $this->assertNotNull($image, 'La imagen del master debe copiarse al spinoff nuevo.');

        $variantEvent = DB::table('sync_outbox')
            ->where('tenant_id', $spinoff->id)
            ->where('event_type', 'product_variant.created')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($variantEvent, 'Debe emitirse product_variant.created para el spinoff nuevo.');

        $imageEvent = DB::table('sync_outbox')
            ->where('tenant_id', $spinoff->id)
            ->where('event_type', 'product.image.uploaded')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($imageEvent, 'Debe emitirse product.image.uploaded para el spinoff nuevo.');
    }

    private function createGroupWithSpinoff(string $spinoffSlug): array
    {
        $group = Tenant::create([
            'name' => 'Danubio',
            'slug' => 'danubio',
            'is_group' => true,
        ]);

        $spinoff = Tenant::create([
            'name' => str_replace('-', ' ', ucwords($spinoffSlug)),
            'slug' => $spinoffSlug,
            'parent_id' => $group->id,
            'is_group' => false,
        ]);

        return [$group, $spinoff];
    }

    private function makeGroupOwner(Tenant $group): User
    {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@danubio.test',
            'password' => bcrypt('secret123'),
        ]);
        $user->tenants()->attach($group, ['status' => 'active']);

        setPermissionsTeamId($group->id);
        $role = Role::create([
            'name' => 'Owner',
            'guard_name' => 'web',
            'tenant_id' => $group->id,
        ]);
        $role->syncPermissions(
            Permission::query()->whereIn('name', BasePermissions::PERMISSIONS)->get()
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($group->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function createWarehouseFor(Tenant $tenant, string $code): Warehouse
    {
        $this->useTenant($tenant);

        $branch = Branch::create([
            'name' => "Sucursal {$code}",
            'code' => "BR-{$code}",
        ]);

        return Warehouse::create([
            'branch_id' => $branch->id,
            'name' => "Almacen {$code}",
            'code' => $code,
        ]);
    }

    private function grantPermissions(Tenant $tenant, string $email, array $permissions): User
    {
        $this->useTenant($tenant);

        $user = User::create([
            'name' => 'Spinoff Admin',
            'email' => $email,
            'password' => bcrypt('secret123'),
        ]);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        setPermissionsTeamId($tenant->id);
        $role = Role::create([
            'name' => 'SpinoffAlmacen',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $role->syncPermissions(
            Permission::query()->whereIn('name', $permissions)->get()
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($tenant->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
