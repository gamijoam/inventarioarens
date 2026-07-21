<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fase 1 - IMEI scanner: valida que el endpoint
 * `GET /api/inventory-centers/products/{product}/units` lista correctamente
 * las ProductUnits disponibles de un almacen, con filtros por status y
 * prefijo de serial_number.
 */
class ProductUnitLookupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    private function bootstrap(): array
    {
        $tenant = Tenant::create(['name' => 'T1', 'slug' => 't1', 'is_group' => true]);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::factory()->create(['email' => 'a@t.test', 'password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $role = Role::create(['name' => 'Admin', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
        $role->givePermissionTo('inventory.view');
        $user->assignRole($role);

        $branch = Branch::create(['name' => 'B1', 'code' => 'B1', 'status' => 'active']);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W1',
            'code' => 'W1',
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Celular',
            'sku' => 'CEL-' . uniqid(),
            'tracking_type' => Product::TRACKING_SERIALIZED,
            'unit_of_measure' => Product::UNIT_UNIT,
            'is_active' => true,
            'tenant_id' => $tenant->id,
        ]);

        $token = Str::random(80);
        DB::table('auth_tokens')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($tenant->id);

        return [$tenant, $user, $token, $warehouse, $product];
    }

    private function makeUnit(int $productId, int $warehouseId, int $tenantId, string $serialNumber, string $status = 'available'): int
    {
        return DB::table('product_units')->insertGetId([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'serial_type' => 'imei',
            'serial_number' => $serialNumber,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_list_units_returns_available_units_only_by_default(): void
    {
        [$tenant, $user, $token, $warehouse, $product] = $this->bootstrap();

        $this->makeUnit($product->id, $warehouse->id, $tenant->id, '352099001761481');
        $this->makeUnit($product->id, $warehouse->id, $tenant->id, '352099001761482');
        $this->makeUnit($product->id, $warehouse->id, $tenant->id, '352099001761483');
        $this->makeUnit($product->id, $warehouse->id, $tenant->id, '352099001761499', 'reserved');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-centers/products/{$product->id}/units?warehouse_id={$warehouse->id}")
            ->assertOk();

        $body = $response->json();
        $this->assertCount(3, $body['data']);
    }

    public function test_list_units_filtered_by_status(): void
    {
        [$tenant, $user, $token, $warehouse, $product] = $this->bootstrap();

        $this->makeUnit($product->id, $warehouse->id, $tenant->id, 'A-001', 'reserved');
        $this->makeUnit($product->id, $warehouse->id, $tenant->id, 'A-002', 'reserved');
        $this->makeUnit($product->id, $warehouse->id, $tenant->id, 'A-003', 'available');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-centers/products/{$product->id}/units?warehouse_id={$warehouse->id}&status=reserved")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_units_search_by_prefix(): void
    {
        [$tenant, $user, $token, $warehouse, $product] = $this->bootstrap();

        $this->makeUnit($product->id, $warehouse->id, $tenant->id, '352099001761481');
        $this->makeUnit($product->id, $warehouse->id, $tenant->id, '352099001761482');
        $this->makeUnit($product->id, $warehouse->id, $tenant->id, '352099001761999');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-centers/products/{$product->id}/units?warehouse_id={$warehouse->id}&search=3520990017614")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_units_warehouse_not_in_tenant_returns_404(): void
    {
        [$tenant, $user, $token, $warehouse, $product] = $this->bootstrap();

        $otherTenant = Tenant::create(['name' => 'T2', 'slug' => 't2', 'is_group' => true]);
        $otherBranch = DB::table('branches')->insertGetId([
            'tenant_id' => $otherTenant->id,
            'name' => 'BO',
            'code' => 'BO-' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherWarehouseId = DB::table('warehouses')->insertGetId([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch,
            'name' => 'WO',
            'code' => 'WO-' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-centers/products/{$product->id}/units?warehouse_id={$otherWarehouseId}");

        $response->assertStatus(404);
    }

    public function test_list_units_missing_warehouse_id_returns_422(): void
    {
        [$tenant, $user, $token, $warehouse, $product] = $this->bootstrap();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-centers/products/{$product->id}/units");

        $response->assertStatus(422);
    }

    public function test_lookup_returns_unit_when_serial_matches(): void
    {
        [$tenant, $user, $token, $warehouse, $product] = $this->bootstrap();

        $this->makeUnit($product->id, $warehouse->id, $tenant->id, '352099001761481');
        $this->makeUnit($product->id, $warehouse->id, $tenant->id, '352099001761482');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-centers/products/units/lookup?warehouse_id={$warehouse->id}&serial=352099001761481")
            ->assertOk();

        $body = $response->json('data');
        $this->assertSame($product->id, $body['product_id']);
        $this->assertSame('352099001761481', $body['serial_number']);
        $this->assertSame('imei', $body['serial_type']);
        $this->assertSame('available', $body['status']);
    }

    public function test_lookup_returns_404_when_serial_does_not_match(): void
    {
        [$tenant, $user, $token, $warehouse, $product] = $this->bootstrap();

        $this->makeUnit($product->id, $warehouse->id, $tenant->id, '352099001761481');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-centers/products/units/lookup?warehouse_id={$warehouse->id}&serial=NO-EXISTE")
            ->assertStatus(404);
    }

    public function test_lookup_returns_unit_even_when_sold_or_removed_for_clarity(): void
    {
        [$tenant, $user, $token, $warehouse, $product] = $this->bootstrap();

        $this->makeUnit($product->id, $warehouse->id, $tenant->id, 'IMEI-SOLD', 'sold');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-centers/products/units/lookup?warehouse_id={$warehouse->id}&serial=IMEI-SOLD")
            ->assertOk();

        // Devolvemos la unidad aunque este sold para que el POS distinga
        // "no existe" (404) vs "ya no esta disponible" (200 + status=sold).
        $this->assertSame('sold', $response->json('data.status'));
    }

    public function test_lookup_rejects_other_tenant_warehouse_with_404(): void
    {
        [$tenant, $user, $token, $warehouse, $product] = $this->bootstrap();

        $otherTenant = Tenant::create(['name' => 'T2', 'slug' => 't2', 'is_group' => true]);
        app(TenantManager::class)->set($otherTenant);
        setPermissionsTeamId($otherTenant->id);
        $otherBranchId = DB::table('branches')->insertGetId([
            'tenant_id' => $otherTenant->id,
            'name' => 'BO',
            'code' => 'BO-' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherWarehouseId = DB::table('warehouses')->insertGetId([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranchId,
            'name' => 'WO',
            'code' => 'WO-' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Creamos un product en el otro tenant con su propio id.
        $otherProduct = Product::create([
            'name' => 'Otro Celular',
            'sku' => 'OTRO-' . uniqid(),
            'tracking_type' => Product::TRACKING_SERIALIZED,
            'unit_of_measure' => Product::UNIT_UNIT,
            'is_active' => true,
            'tenant_id' => $otherTenant->id,
        ]);
        DB::table('product_units')->insert([
            'tenant_id' => $otherTenant->id,
            'product_id' => $otherProduct->id,
            'warehouse_id' => $otherWarehouseId,
            'serial_type' => 'imei',
            'serial_number' => 'IMEI-OTRO-TENANT',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        // El lookup con warehouse de otro tenant debe dar 404 (multi-tenancy).
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-centers/products/units/lookup?warehouse_id={$otherWarehouseId}&serial=IMEI-OTRO-TENANT")
            ->assertStatus(404);
    }

    public function test_lookup_validates_required_params(): void
    {
        [$tenant, $user, $token, $warehouse, $product] = $this->bootstrap();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-centers/products/units/lookup")
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-centers/products/units/lookup?warehouse_id={$warehouse->id}")
            ->assertStatus(422);
    }
}