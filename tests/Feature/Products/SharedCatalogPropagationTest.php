<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
