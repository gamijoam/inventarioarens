<?php

namespace Tests\Feature\AdminPortal;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminTransfersListTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_transfers_with_default_pagination(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-LIST');
        $this->stock($tenant, $from, $product, 20);

        $this->createTransferViaApi($user, $tenant, $from, $to, $product, 5);
        $this->createTransferViaApi($user, $tenant, $from, $to, $product, 3);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/transfers')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.limit', 25)
            ->assertJsonPath('data.pagination.page', 1);

        $rows = $response->json('data.data');
        $this->assertCount(2, $rows);
        $this->assertSame(InventoryTransfer::STATUS_COMPLETED, $rows[0]['status']);
        $this->assertSame(InventoryTransfer::STATUS_COMPLETED, $rows[1]['status']);
        $this->assertSame(1, (int) $rows[0]['items_count']);
        $this->assertSame(0, (int) $rows[0]['differences_count']);
    }

    public function test_admin_can_filter_by_single_status(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-FLT-1');
        $this->stock($tenant, $from, $product, 50);

        $this->createTransferViaApi($user, $tenant, $from, $to, $product, 2);
        $cancelled = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $cancelled->update(['status' => InventoryTransfer::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/transfers?status[]=cancelled')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.status', InventoryTransfer::STATUS_CANCELLED);
    }

    public function test_admin_can_filter_by_multiple_statuses(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-FLT-M');
        $this->stock($tenant, $from, $product, 50);

        $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t2 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t2->update(['status' => InventoryTransfer::STATUS_CANCELLED, 'cancelled_at' => now()]);
        $t3 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t3->update(['status' => InventoryTransfer::STATUS_DISPATCHED, 'dispatched_at' => now()]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/transfers?status[]=cancelled&status[]=dispatched')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_admin_can_filter_by_origin_warehouse(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$fromA, $toA, $product] = $this->warehousesAndProduct($tenant, 'TR-ORIG', codePrefix: 'WH-A');
        $this->stock($tenant, $fromA, $product, 20);

        $this->createTransferViaApi($user, $tenant, $fromA, $toA, $product, 2);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/admin-portal/transfers?warehouse_id={$fromA->id}")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.from_warehouse_id', $fromA->id);
    }

    public function test_admin_can_filter_by_destination_warehouse(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $toB, $product] = $this->warehousesAndProduct($tenant, 'TR-DEST', codePrefix: 'WH-B');
        $this->stock($tenant, $from, $product, 20);

        $this->createTransferViaApi($user, $tenant, $from, $toB, $product, 2);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/admin-portal/transfers?warehouse_id={$toB->id}")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.to_warehouse_id', $toB->id);
    }

    public function test_admin_can_filter_by_date_range(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-DATE');
        $this->stock($tenant, $from, $product, 50);

        $t1 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t1->update(['processed_at' => '2026-07-01 09:00:00']);

        $t2 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t2->update(['processed_at' => '2026-07-08 09:00:00']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/transfers?date_from=2026-07-05&date_to=2026-07-09')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.id', $t2->id);
    }

    public function test_admin_can_search_by_document_number(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-SRCH');
        $this->stock($tenant, $from, $product, 20);

        $t1 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t2 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/admin-portal/transfers?search={$t1->document_number}")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.id', $t1->id);
    }

    public function test_admin_can_combine_multiple_filters(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-COMBO');
        $this->stock($tenant, $from, $product, 50);

        $t1 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t1->update([
            'status' => InventoryTransfer::STATUS_IN_PREPARATION,
            'processed_at' => '2026-07-08 09:00:00',
        ]);

        $t2 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t2->update([
            'status' => InventoryTransfer::STATUS_IN_PREPARATION,
            'processed_at' => '2026-07-02 09:00:00',
        ]);

        $t3 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t3->update(['status' => InventoryTransfer::STATUS_DISPATCHED]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/admin-portal/transfers?status[]=in_preparation&warehouse_id={$from->id}&date_from=2026-07-05&date_to=2026-07-10")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.id', $t1->id);
    }

    public function test_summary_returns_correct_status_counts(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-SUM');
        $this->stock($tenant, $from, $product, 50);

        $t1 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t1->update(['status' => InventoryTransfer::STATUS_DISPATCHED, 'dispatched_at' => now()]);

        $t2 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t2->update(['status' => InventoryTransfer::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $t3 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t3->update(['status' => InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES, 'resolution_status' => InventoryTransfer::RESOLUTION_PARTIAL]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/transfers/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.in_flight', 1)
            ->assertJsonPath('data.with_differences', 1)
            ->assertJsonPath('data.by_status.dispatched', 1)
            ->assertJsonPath('data.by_status.cancelled', 1)
            ->assertJsonPath('data.by_status.completed_with_differences', 1)
            ->assertJsonPath('data.status_labels.dispatched', 'Despachado');
    }

    public function test_summary_honors_filter_arguments(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-SUMFLT');
        $this->stock($tenant, $from, $product, 50);

        $t1 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t1->update(['status' => InventoryTransfer::STATUS_DISPATCHED]);

        $t2 = $this->createTransferViaApi($user, $tenant, $from, $to, $product, 1);
        $t2->update(['status' => InventoryTransfer::STATUS_CANCELLED]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/transfers/summary?status[]=dispatched')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.by_status.dispatched', 1)
            ->assertJsonPath('data.by_status.cancelled', 0);
    }

    public function test_user_without_admin_permission_gets_403(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant, ['inventory_transfers.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/transfers')
            ->assertForbidden();
    }

    public function test_summary_endpoint_also_requires_admin_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant, ['inventory_transfers.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/transfers/summary')
            ->assertForbidden();
    }

    public function test_admin_does_not_see_other_tenant_transfers(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);

        $this->useTenant($tenantA);
        [$fromA, $toA, $productA] = $this->warehousesAndProduct($tenantA, 'TR-A');
        $this->stock($tenantA, $fromA, $productA, 10);
        $this->createTransferViaApi($userA, $tenantA, $fromA, $toA, $productA, 2);

        $this->useTenant($tenantB);
        [$fromB, $toB, $productB] = $this->warehousesAndProduct($tenantB, 'TR-B');
        $this->stock($tenantB, $fromB, $productB, 10);
        $userB = $this->userInTenant($tenantB);
        $this->createTransferViaApi($userB, $tenantB, $fromB, $toB, $productB, 5);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/admin-portal/transfers')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_summary_includes_warehouse_options(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Traslados', 'slug' => 'empresa-traslados']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-WH');
        $this->stock($tenant, $from, $product, 10);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/transfers/summary')
            ->assertOk()
            ->assertJsonCount(2, 'data.warehouses');
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function userInTenant(Tenant $tenant, ?array $permissions = null): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $permissions ??= [
            'inventory_transfers.admin',
            'inventory_transfers.view',
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.cancel',
            'inventory_transfers.resolve_differences',
        ];

        $this->grantRole($tenant, $user, 'Traslados '.md5(implode('|', $permissions).$tenant->id), $permissions);

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

    private function warehousesAndProduct(Tenant $tenant, string $sku, string $codePrefix = 'WH'): array
    {
        $branch = Branch::firstOrCreate(['code' => $codePrefix.'-B-'.$tenant->id], ['name' => 'Sucursal '.$codePrefix]);
        $from = Warehouse::firstOrCreate(
            ['code' => $codePrefix.'-FROM-'.$tenant->id.'-'.$sku],
            ['branch_id' => $branch->id, 'name' => 'Origen '.$codePrefix]
        );
        $to = Warehouse::firstOrCreate(
            ['code' => $codePrefix.'-TO-'.$tenant->id.'-'.$sku],
            ['branch_id' => $branch->id, 'name' => 'Destino '.$codePrefix]
        );
        $product = Product::create([
            'name' => 'Producto '.$sku,
            'sku' => $sku,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$from, $to, $product];
    }

    private function stock(Tenant $tenant, Warehouse $warehouse, Product $product, float $quantity): void
    {
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => $quantity,
        ]);
    }

    private function createTransferViaApi(User $user, Tenant $tenant, Warehouse $from, Warehouse $to, Product $product, float $quantity): InventoryTransfer
    {
        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'reason' => 'Traslado test',
                'reference' => 'REF-'.$product->sku,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ]],
            ]);

        $response->assertCreated();

        $id = $response->json('data.id');

        return InventoryTransfer::findOrFail($id);
    }
}
