<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Modules\InventoryTransferRequests\Services\InventoryTransferRequestService;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncEventApplier;
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

class InventoryTransferRequestSyncTest extends TestCase
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

    private function setupTenants(): array
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        $userA = User::factory()->create();
        $userA->tenants()->attach($tenantA, ['status' => 'active']);
        $userB = User::factory()->create();
        $userB->tenants()->attach($tenantB, ['status' => 'active']);

        app(TenantManager::class)->set($tenantA);
        setPermissionsTeamId($tenantA->id);
        $branchA = Branch::create(['name' => 'Branch A', 'code' => 'BR-A']);
        $warehouseA = Warehouse::create(['branch_id' => $branchA->id, 'name' => 'WH A', 'code' => 'WH-A']);
        $productA = Product::create(['name' => 'Prod A', 'sku' => 'SKU-A', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 10, 'sale_currency' => 'USD']);

        app(TenantManager::class)->set($tenantB);
        setPermissionsTeamId($tenantB->id);
        $branchB = Branch::create(['name' => 'Branch B', 'code' => 'BR-B']);
        $warehouseB = Warehouse::create(['branch_id' => $branchB->id, 'name' => 'WH B', 'code' => 'WH-B']);
        $productB = Product::create(['name' => 'Prod B', 'sku' => 'SKU-B', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 10, 'sale_currency' => 'USD']);

        DB::table('stock_balances')->insert([
            ['tenant_id' => $tenantA->id, 'warehouse_id' => $warehouseA->id, 'product_id' => $productA->id, 'quantity_available' => 100, 'quantity_reserved' => 0, 'quantity_damaged' => 0],
            ['tenant_id' => $tenantB->id, 'warehouse_id' => $warehouseB->id, 'product_id' => $productB->id, 'quantity_available' => 50, 'quantity_reserved' => 0, 'quantity_damaged' => 0],
        ]);

        $role = Role::firstOrCreate(['name' => 'ITR Creator A', 'guard_name' => 'web']);
        $role->syncPermissions([
            'inventory_transfer_requests.view',
            'inventory_transfer_requests.create',
            'inventory_transfer_requests.cancel',
        ]);
        $userA->assignRole($role);

        $roleB = Role::firstOrCreate(['name' => 'ITR Acceptor B', 'guard_name' => 'web']);
        $roleB->syncPermissions([
            'inventory_transfer_requests.view',
            'inventory_transfer_requests.respond',
        ]);
        $userB->assignRole($roleB);

        return [
            'tenantA' => $tenantA,
            'tenantB' => $tenantB,
            'userA' => $userA,
            'userB' => $userB,
            'warehouseA' => $warehouseA,
            'warehouseB' => $warehouseB,
            'productA' => $productA,
            'productB' => $productB,
        ];
    }

    private function createRequest(Tenant $tenantA, User $userA, Warehouse $warehouseA, Product $productA, Tenant $tenantB, Product $productB, Warehouse $warehouseB, float $quantity = 10): InventoryTransferRequest
    {
        app(TenantManager::class)->set($tenantA);
        setPermissionsTeamId($tenantA->id);

        $service = app(InventoryTransferRequestService::class);

        $request = $service->create($userA, [
            'destination_tenant_slug' => $tenantB->slug,
            'from_warehouse_id' => $warehouseA->id,
            'reason' => 'Sync test',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => $quantity],
            ],
        ]);

        return $request;
    }

    public function test_created_event_syncs_to_cloud_and_creates_request_record(): void
    {
        $data = $this->setupTenants();

        $request = $this->createRequest(
            $data['tenantA'], $data['userA'], $data['warehouseA'], $data['productA'],
            $data['tenantB'], $data['productB'], $data['warehouseB']
        );

        DB::table('sync_inbox')->insert([
            'tenant_id' => $data['tenantA']->id,
            'event_uuid' => '11111111-1111-1111-1111-111111111111',
            'event_type' => 'inventory_transfer_request.created',
            'aggregate_type' => 'inventory_transfer_request',
            'aggregate_id' => $request->id,
            'payload_hash' => null,
            'payload' => json_encode([
                'id' => $request->id,
                'document_number' => $request->document_number,
                'sequence' => $request->sequence,
                'origin_tenant_id' => $request->origin_tenant_id,
                'destination_tenant_id' => $request->destination_tenant_id,
                'from_warehouse_id' => $request->from_warehouse_id,
                'destination_warehouse_id' => null,
                'status' => $request->status,
                'reason' => $request->reason,
                'reference' => null,
                'notes' => null,
                'response_notes' => null,
                'requested_by' => $request->requested_by,
                'responded_by' => null,
                'requested_at' => $request->requested_at?->toJSON(),
                'responded_at' => null,
                'completed_at' => null,
                'items' => $request->items->map(fn ($i) => [
                    'id' => $i->id,
                    'origin_product_id' => $i->origin_product_id,
                    'destination_product_id' => $i->destination_product_id,
                    'quantity' => (string) $i->quantity,
                    'product_unit_ids' => [],
                    'serial_units' => [],
                    'out_stock_movement_id' => null,
                    'in_stock_movement_id' => null,
                ])->values()->all(),
            ]),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncEventApplier::class)->applyOne($data['tenantA'], (array) DB::table('sync_inbox')->where('event_uuid', '11111111-1111-1111-1111-111111111111')->first());

        $this->assertTrue(
            DB::table('inventory_transfer_requests')
                ->where('origin_tenant_id', $data['tenantA']->id)
                ->where('sequence', $request->sequence)
                ->where('status', 'requested')
                ->exists(),
            'test_created: la fila no quedo en status=requested'
        );

        $this->assertDatabaseHas('sync_inbox', [
            'event_uuid' => '11111111-1111-1111-1111-111111111111',
            'status' => 'applied',
        ]);
    }

    public function test_accepted_event_syncs_stock_movement_to_both_tenants(): void
    {
        $data = $this->setupTenants();

        DB::table('products')
            ->whereIn('id', [$data['productA']->id, $data['productB']->id])
            ->update(['tracking_type' => Product::TRACKING_SERIALIZED]);
        DB::table('product_units')->insert([
            [
                'tenant_id' => $data['tenantB']->id,
                'product_id' => $data['productB']->id,
                'warehouse_id' => $data['warehouseB']->id,
                'serial_type' => 'imei',
                'serial_number' => 'SYNC-IMEI-001',
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $data['tenantB']->id,
                'product_id' => $data['productB']->id,
                'warehouse_id' => $data['warehouseB']->id,
                'serial_type' => 'imei',
                'serial_number' => 'SYNC-IMEI-002',
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $request = $this->createRequest(
            $data['tenantA'], $data['userA'], $data['warehouseA'], $data['productA'],
            $data['tenantB'], $data['productB'], $data['warehouseB'], 2
        );

        DB::table('sync_inbox')->insert([
            'tenant_id' => $data['tenantA']->id,
            'event_uuid' => '22222222-2222-2222-2222-222222222222',
            'event_type' => 'inventory_transfer_request.accepted',
            'aggregate_type' => 'inventory_transfer_request',
            'aggregate_id' => $request->id,
            'payload_hash' => null,
            'payload' => json_encode([
                'id' => $request->id,
                'document_number' => $request->document_number,
                'sequence' => $request->sequence,
                'origin_tenant_id' => $request->origin_tenant_id,
                'destination_tenant_id' => $request->destination_tenant_id,
                'from_warehouse_id' => $request->from_warehouse_id,
                'destination_warehouse_id' => $data['warehouseB']->id,
                'status' => 'completed',
                'reason' => $request->reason,
                'reference' => null,
                'notes' => null,
                'response_notes' => 'Recibido OK',
                'requested_by' => $request->requested_by,
                'responded_by' => $data['userB']->id,
                'requested_at' => $request->requested_at?->toJSON(),
                'responded_at' => now()->toJSON(),
                'completed_at' => now()->toJSON(),
                'items' => [
                    [
                        'id' => $request->items->first()->id,
                        'origin_product_id' => $data['productA']->id,
                        'destination_product_id' => $data['productB']->id,
                        'quantity' => '2.0000',
                        'product_unit_ids' => [],
                        'serial_units' => [
                            ['serial_type' => 'imei', 'serial_number' => 'SYNC-IMEI-001'],
                            ['serial_type' => 'imei', 'serial_number' => 'SYNC-IMEI-002'],
                        ],
                        'out_stock_movement_id' => null,
                        'in_stock_movement_id' => null,
                    ],
                ],
            ]),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncEventApplier::class)->applyOne($data['tenantA'], (array) DB::table('sync_inbox')->where('event_uuid', '22222222-2222-2222-2222-222222222222')->first());

        $this->assertSame('102.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $data['tenantA']->id)
            ->where('warehouse_id', $data['warehouseA']->id)
            ->where('product_id', $data['productA']->id)
            ->value('quantity_available'));

        $this->assertSame('48.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $data['tenantB']->id)
            ->where('warehouse_id', $data['warehouseB']->id)
            ->where('product_id', $data['productB']->id)
            ->value('quantity_available'));

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $data['tenantB']->id,
            'type' => 'exit',
            'reference_type' => 'product_exit',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $data['tenantA']->id,
            'type' => 'entry',
            'reference_type' => 'product_entry',
        ]);

        $this->assertDatabaseHas('inventory_transfer_requests', [
            'origin_tenant_id' => $data['tenantA']->id,
            'sequence' => $request->sequence,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('product_exit_items', [
            'tenant_id' => $data['tenantB']->id,
            'product_id' => $data['productB']->id,
            'quantity' => '2.0000',
        ]);

        $this->assertDatabaseHas('product_entry_items', [
            'tenant_id' => $data['tenantA']->id,
            'product_id' => $data['productA']->id,
            'quantity' => '2.0000',
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $data['tenantB']->id,
            'serial_number' => 'SYNC-IMEI-001',
            'status' => 'removed',
            'warehouse_id' => null,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $data['tenantA']->id,
            'product_id' => $data['productA']->id,
            'warehouse_id' => $data['warehouseA']->id,
            'serial_number' => 'SYNC-IMEI-001',
            'status' => 'available',
        ]);
    }

    public function test_rejected_event_only_updates_status_no_stock_movement(): void
    {
        $data = $this->setupTenants();

        $request = $this->createRequest(
            $data['tenantA'], $data['userA'], $data['warehouseA'], $data['productA'],
            $data['tenantB'], $data['productB'], $data['warehouseB']
        );

        $stockBalanceA_before = (float) DB::table('stock_balances')
            ->where('tenant_id', $data['tenantA']->id)
            ->where('warehouse_id', $data['warehouseA']->id)
            ->where('product_id', $data['productA']->id)
            ->value('quantity_available');

        DB::table('sync_inbox')->insert([
            'tenant_id' => $data['tenantA']->id,
            'event_uuid' => '33333333-3333-3333-3333-333333333333',
            'event_type' => 'inventory_transfer_request.rejected',
            'aggregate_type' => 'inventory_transfer_request',
            'aggregate_id' => $request->id,
            'payload_hash' => null,
            'payload' => json_encode([
                'id' => $request->id,
                'document_number' => $request->document_number,
                'sequence' => $request->sequence,
                'origin_tenant_id' => $request->origin_tenant_id,
                'destination_tenant_id' => $request->destination_tenant_id,
                'from_warehouse_id' => $request->from_warehouse_id,
                'destination_warehouse_id' => null,
                'status' => 'rejected',
                'reason' => $request->reason,
                'reference' => null,
                'notes' => null,
                'response_notes' => 'No stock suficiente',
                'requested_by' => $request->requested_by,
                'responded_by' => $data['userB']->id,
                'requested_at' => $request->requested_at?->toJSON(),
                'responded_at' => now()->toJSON(),
                'completed_at' => null,
                'items' => $request->items->map(fn ($i) => [
                    'id' => $i->id,
                    'origin_product_id' => $i->origin_product_id,
                    'destination_product_id' => $i->destination_product_id,
                    'quantity' => (string) $i->quantity,
                    'product_unit_ids' => [],
                    'serial_units' => [],
                    'out_stock_movement_id' => null,
                    'in_stock_movement_id' => null,
                ])->values()->all(),
            ]),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncEventApplier::class)->applyOne($data['tenantA'], (array) DB::table('sync_inbox')->where('event_uuid', '33333333-3333-3333-3333-333333333333')->first());

        $stockBalanceA_after = (float) DB::table('stock_balances')
            ->where('tenant_id', $data['tenantA']->id)
            ->where('warehouse_id', $data['warehouseA']->id)
            ->where('product_id', $data['productA']->id)
            ->value('quantity_available');

        $this->assertSame($stockBalanceA_before, $stockBalanceA_after, 'Stock no debe cambiar en rejected');

        $this->assertTrue(
            DB::table('inventory_transfer_requests')
                ->where('origin_tenant_id', $data['tenantA']->id)
                ->where('sequence', $request->sequence)
                ->where('status', 'rejected')
                ->exists(),
            'test_rejected: la fila no quedo en status=rejected'
        );
    }

    public function test_cancelled_event_only_updates_status_no_stock_movement(): void
    {
        $data = $this->setupTenants();

        $request = $this->createRequest(
            $data['tenantA'], $data['userA'], $data['warehouseA'], $data['productA'],
            $data['tenantB'], $data['productB'], $data['warehouseB']
        );

        $stockBalanceA_before = (float) DB::table('stock_balances')
            ->where('tenant_id', $data['tenantA']->id)
            ->where('warehouse_id', $data['warehouseA']->id)
            ->where('product_id', $data['productA']->id)
            ->value('quantity_available');

        DB::table('sync_inbox')->insert([
            'tenant_id' => $data['tenantA']->id,
            'event_uuid' => '44444444-4444-4444-4444-444444444444',
            'event_type' => 'inventory_transfer_request.cancelled',
            'aggregate_type' => 'inventory_transfer_request',
            'aggregate_id' => $request->id,
            'payload_hash' => null,
            'payload' => json_encode([
                'id' => $request->id,
                'document_number' => $request->document_number,
                'sequence' => $request->sequence,
                'origin_tenant_id' => $request->origin_tenant_id,
                'destination_tenant_id' => $request->destination_tenant_id,
                'from_warehouse_id' => $request->from_warehouse_id,
                'destination_warehouse_id' => null,
                'status' => 'cancelled',
                'reason' => $request->reason,
                'reference' => null,
                'notes' => null,
                'response_notes' => null,
                'requested_by' => $request->requested_by,
                'responded_by' => $data['userA']->id,
                'requested_at' => $request->requested_at?->toJSON(),
                'responded_at' => now()->toJSON(),
                'completed_at' => null,
                'items' => $request->items->map(fn ($i) => [
                    'id' => $i->id,
                    'origin_product_id' => $i->origin_product_id,
                    'destination_product_id' => $i->destination_product_id,
                    'quantity' => (string) $i->quantity,
                    'product_unit_ids' => [],
                    'serial_units' => [],
                    'out_stock_movement_id' => null,
                    'in_stock_movement_id' => null,
                ])->values()->all(),
            ]),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncEventApplier::class)->applyOne($data['tenantA'], (array) DB::table('sync_inbox')->where('event_uuid', '44444444-4444-4444-4444-444444444444')->first());

        $stockBalanceA_after = (float) DB::table('stock_balances')
            ->where('tenant_id', $data['tenantA']->id)
            ->where('warehouse_id', $data['warehouseA']->id)
            ->where('product_id', $data['productA']->id)
            ->value('quantity_available');

        $this->assertSame($stockBalanceA_before, $stockBalanceA_after, 'Stock no debe cambiar en cancelled');

        $this->assertTrue(
            DB::table('inventory_transfer_requests')
                ->where('origin_tenant_id', $data['tenantA']->id)
                ->where('sequence', $request->sequence)
                ->where('status', 'cancelled')
                ->exists(),
            'test_cancelled: la fila no quedo en status=cancelled'
        );
    }
}
