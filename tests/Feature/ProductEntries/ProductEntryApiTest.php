<?php

namespace Tests\Feature\ProductEntries;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductEntryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_quantity_product_entry(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'AUD-ENTRY', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['product_entries.create', 'product_entries.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-entries', [
                'reason' => 'Carga inicial',
                'reference' => 'GUIA-001',
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 20,
                    'unit_cost' => 10,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.document_number', 'ENT-000001')
            ->assertJsonPath('data.items.0.quantity', '20.0000');

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '20.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => '20.0000',
            'reference_type' => ProductEntry::class,
        ]);
    }

    public function test_user_can_create_serialized_product_entry_with_thirty_imeis(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'SAM-A06-ENTRY', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['product_entries.create', 'product_entries.view']);
        $imeis = $this->imeis('860900');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-entries', [
                'reason' => 'Compra Samsung A06',
                'reference' => 'FACT-IMEI-001',
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 30,
                    'unit_cost' => 80,
                    'serial_units' => $imeis,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.serial_units.29.serial_number', '860900000000030');

        $this->assertSame(30, ProductUnit::query()->where('product_id', $product->id)->count());
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860900000000030',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $this->assertSame('30.0000', StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity_available'));
    }

    public function test_serialized_entry_requires_exact_unique_serials(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'SAM-A06-ERR', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['product_entries.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-entries', [
                'reason' => 'Entrada incompleta',
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'serial_units' => [[
                        'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                        'serial_number' => '860901000000001',
                    ]],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.serial_units']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-entries', [
                'reason' => 'Entrada duplicada',
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'serial_units' => [
                        ['serial_type' => ProductUnit::SERIAL_TYPE_IMEI, 'serial_number' => '860901000000001'],
                        ['serial_type' => ProductUnit::SERIAL_TYPE_IMEI, 'serial_number' => '860901000000001'],
                    ],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.serial_units.1.serial_number']);
    }

    public function test_product_entries_do_not_mix_companies_and_reject_foreign_resources(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$warehouseA, $productA] = $this->warehouseAndProduct($tenantA, 'ENTRY-A', Product::TRACKING_QUANTITY);
        [$warehouseB, $productB] = $this->warehouseAndProduct($tenantB, 'ENTRY-B', Product::TRACKING_QUANTITY);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Almacen A', ['product_entries.create', 'product_entries.view']);
        $this->grantRole($tenantB, $userB, 'Almacen B', ['product_entries.create', 'product_entries.view']);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/product-entries', [
                'reason' => 'Entrada A',
                'items' => [[
                    'warehouse_id' => $warehouseA->id,
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/product-entries', [
                'reason' => 'Entrada B',
                'items' => [[
                    'warehouse_id' => $warehouseB->id,
                    'product_id' => $productB->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/product-entries')
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'Entrada A')
            ->assertJsonPath('data.0.items.0.product.sku', 'ENTRY-A')
            ->assertJsonPath('data.0.items.0.warehouse.code', 'WH-ENTRY-A')
            ->assertJsonMissing(['reason' => 'Entrada B']);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/product-entries', [
                'reason' => 'Entrada cruzada',
                'items' => [[
                    'warehouse_id' => $warehouseB->id,
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.warehouse_id']);
    }

    public function test_product_entry_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'ENTRY-NOAUTH', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-entries', [
                'reason' => 'Sin permiso',
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertForbidden();
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function warehouseAndProduct(Tenant $tenant, string $sku, string $trackingType): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$sku}", 'code' => "BR-{$sku}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$sku}", 'code' => "WH-{$sku}"]);
        $product = Product::create([
            'name' => "Producto {$sku}",
            'sku' => $sku,
            'tracking_type' => $trackingType,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$warehouse, $product];
    }

    private function imeis(string $prefix): array
    {
        return array_map(
            fn (int $index): array => [
                'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                'serial_number' => $prefix.str_pad((string) $index, 9, '0', STR_PAD_LEFT),
            ],
            range(1, 30)
        );
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
