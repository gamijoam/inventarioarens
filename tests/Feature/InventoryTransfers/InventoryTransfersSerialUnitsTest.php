<?php

namespace Tests\Feature\InventoryTransfers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
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
 * Tests del flujo de serial_units en InventoryTransfer::create.
 *
 * Estado (Fase 0):
 * - Backend: logica central en InventoryMovementService valida que los
 *   serial_units existan y esten disponibles antes del traslado.
 * - Frontend: TransferCreateDialog envia serial_units con {serial_type, serial_number}[].
 * - Frontend: TransferPrepareDialog y TransferReceiveDialog reusan el patron.
 */
class InventoryTransfersSerialUnitsTest extends TestCase
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

    /**
     * @test
     * Backend: si llegan serial_units en el payload, se traducen a
     * product_unit_ids existentes y disponibles.
     */
    public function test_serial_units_resolves_to_existing_product_units(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'slug' => 't1', 'is_group' => true]);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::factory()->create(['email' => 'a@t.test', 'password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $role = Role::create([
            'name' => 'Admin',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $role->givePermissionTo('inventory_transfers.view');
        $role->givePermissionTo('inventory_transfers.create');
        $user->assignRole($role);

        $branch = Branch::create(['name' => 'B1', 'code' => 'B1', 'status' => 'active']);
        $fromW = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'AO',
            'code' => 'AO',
            'status' => 'active',
        ]);
        $toW = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'AD',
            'code' => 'AD',
            'status' => 'active',
        ]);
        $product = Product::create([
            'name' => 'Celular Test',
            'sku' => 'IMEI-'.uniqid(),
            'tracking_type' => Product::TRACKING_SERIALIZED,
            'unit_of_measure' => Product::UNIT_UNIT,
            'is_active' => true,
            'tenant_id' => $tenant->id,
        ]);

        $serials = ['352099001761481', '352099001761482', '352099001761483'];
        foreach ($serials as $sn) {
            DB::table('product_units')->insert([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'warehouse_id' => $fromW->id,
                'serial_type' => 'imei',
                'serial_number' => $sn,
                'status' => ProductUnit::STATUS_AVAILABLE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('stock_balances')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $fromW->id,
            'product_id' => $product->id,
            'quantity_available' => 3,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
            'updated_at' => now(),
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

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromW->id,
                'to_warehouse_id' => $toW->id,
                'reason' => 'Test serial_units',
                'reference' => 'TRF-SU',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'serial_units' => array_map(fn ($sn) => [
                        'serial_type' => 'imei',
                        'serial_number' => $sn,
                    ], $serials),
                ]],
            ]);

        $response->assertCreated();
        $transferId = $response->json('data.id');
        $item = InventoryTransfer::findOrFail($transferId)
            ->items()->first();

        $this->assertCount(3, $item->product_unit_ids);
    }

    /**
     * @test
     * Backend: un traslado no puede crear unidades serializadas nuevas.
     */
    public function test_serial_units_creates_new_product_units_when_missing(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'slug' => 't1', 'is_group' => true]);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::factory()->create(['email' => 'a@t.test', 'password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $role = Role::create([
            'name' => 'Admin',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $role->givePermissionTo('inventory_transfers.view');
        $role->givePermissionTo('inventory_transfers.create');
        $user->assignRole($role);

        $branch = Branch::create(['name' => 'B1', 'code' => 'B1', 'status' => 'active']);
        $fromW = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'AO',
            'code' => 'AO',
            'status' => 'active',
        ]);
        $toW = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'AD',
            'code' => 'AD',
            'status' => 'active',
        ]);
        $product = Product::create([
            'name' => 'Celular Test',
            'sku' => 'IMEI-'.uniqid(),
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

        $newSerials = ['990000862471854', '990000862471861'];

        DB::table('stock_balances')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $fromW->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromW->id,
                'to_warehouse_id' => $toW->id,
                'reason' => 'Test new IMEIs',
                'reference' => 'TRF-NEW',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'serial_units' => array_map(fn ($sn) => [
                        'serial_type' => 'imei',
                        'serial_number' => $sn,
                    ], $newSerials),
                ]],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_units.0']);

        $this->assertDatabaseMissing('inventory_transfers', ['tenant_id' => $tenant->id]);
        foreach ($newSerials as $sn) {
            $unit = ProductUnit::where('serial_number', $sn)->first();
            $this->assertNull($unit);
        }
    }
}
