<?php

namespace Tests\Feature\InventoryTransfers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Models\InventoryTransferChecklist;
use App\Modules\InventoryTransfers\Models\InventoryTransferItem;
use App\Modules\InventoryTransfers\Models\InventoryTransferGuide;
use App\Modules\Products\Models\Product;
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

class InventoryTransferApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_quantity_inventory_transfer(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-QTY', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['inventory_transfers.create', 'inventory_transfers.view']);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Reposicion de sucursal',
                'reference' => 'TRAS-001',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 4,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.document_number', 'TRF-000001')
            ->assertJsonPath('data.guide_number', 'GUIA-000001')
            ->assertJsonPath('data.type', InventoryTransfer::TYPE_INTERNAL)
            ->assertJsonPath('data.validation_mode', InventoryTransfer::VALIDATION_SIMPLE)
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED)
            ->assertJsonPath('data.guide.guide_number', 'GUIA-000001')
            ->assertJsonPath('data.guide.status', 'completed')
            ->assertJsonPath('data.items.0.quantity', 4)
            ->assertJsonPath('data.items.0.requested_quantity', 4)
            ->assertJsonPath('data.items.0.prepared_quantity', 4)
            ->assertJsonPath('data.items.0.received_quantity', 4)
            ->assertJsonPath('data.items.0.difference_quantity', 0);

        $this->assertSame(6.0, (float) $this->balance($fromWarehouse, $product)->quantity_available);
        $this->assertSame(4.0, (float) $this->balance($toWarehouse, $product)->quantity_available);
        $this->assertDatabaseHas('inventory_transfer_guides', [
            'tenant_id' => $tenant->id,
            'guide_number' => 'GUIA-000001',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $fromWarehouse->id,
            'product_id' => $product->id,
            'type' => 'transfer_out',
            'quantity' => '4.0000',
            'reference_type' => InventoryTransfer::class,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $toWarehouse->id,
            'product_id' => $product->id,
            'type' => 'transfer_in',
            'quantity' => '4.0000',
            'reference_type' => InventoryTransfer::class,
        ]);
    }

    public function test_user_can_transfer_serialized_product_units_to_another_warehouse(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-IMEI', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['inventory_transfers.create', 'inventory_transfers.view']);
        $movement = $this->stock($tenant, $fromWarehouse, $product, $user, 3);
        $units = $this->units($tenant, $fromWarehouse, $product, $movement->id, '861000', 3);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.product_unit_ids.1', $units[1]->id);

        $this->assertSame(1.0, (float) $this->balance($fromWarehouse, $product)->quantity_available);
        $this->assertSame(2.0, (float) $this->balance($toWarehouse, $product)->quantity_available);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[0]->id,
            'warehouse_id' => $toWarehouse->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[2]->id,
            'warehouse_id' => $fromWarehouse->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
    }

    public function test_user_can_filter_inventory_transfers_for_desktop_reception(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-FLT', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['inventory_transfers.create', 'inventory_transfers.view']);
        $this->stock($tenant, $fromWarehouse, $product, $user, 12);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Traslado simple',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Traslado logistico',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 3,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-transfers?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', InventoryTransfer::STATUS_COMPLETED)
            ->assertJsonPath('data.0.items.0.product.name', $product->name);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-transfers?validation_mode=logistics')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.validation_mode', InventoryTransfer::VALIDATION_LOGISTICS);
    }

    public function test_user_can_create_logistic_transfer_request_without_moving_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-LOG', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['inventory_transfers.create', 'inventory_transfers.view']);
        $this->stock($tenant, $fromWarehouse, $product, $user, 8);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Preparar envio con checklist',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 5,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.document_number', 'TRF-000001')
            ->assertJsonPath('data.guide_number', 'GUIA-000001')
            ->assertJsonPath('data.validation_mode', InventoryTransfer::VALIDATION_LOGISTICS)
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_REQUESTED)
            ->assertJsonPath('data.guide.status', InventoryTransferGuide::STATUS_GENERATED)
            ->assertJsonPath('data.guide.checklists.0.stage', InventoryTransferChecklist::STAGE_PREPARATION)
            ->assertJsonPath('data.guide.checklists.0.status', InventoryTransferChecklist::STATUS_PENDING)
            ->assertJsonPath('data.guide.checklists.0.items.0.expected_quantity', '5.0000')
            ->assertJsonPath('data.items.0.requested_quantity', 5)
            ->assertJsonPath('data.items.0.prepared_quantity', 0)
            ->assertJsonPath('data.items.0.received_quantity', 0);

        $this->assertSame(8.0, (float) $this->balance($fromWarehouse, $product)->quantity_available);
        $this->assertNull($this->balanceOrNull($toWarehouse, $product));
        $this->assertDatabaseMissing('stock_movements', [
            'tenant_id' => $tenant->id,
            'reference_type' => InventoryTransfer::class,
            'type' => 'transfer_out',
        ]);
        $this->assertDatabaseHas('inventory_transfer_guides', [
            'tenant_id' => $tenant->id,
            'guide_number' => 'GUIA-000001',
            'status' => InventoryTransferGuide::STATUS_GENERATED,
        ]);
        $this->assertDatabaseHas('inventory_transfer_checklists', [
            'tenant_id' => $tenant->id,
            'stage' => InventoryTransferChecklist::STAGE_PREPARATION,
            'status' => InventoryTransferChecklist::STATUS_PENDING,
        ]);
    }

    public function test_user_can_prepare_logistic_transfer_and_reserve_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-PREP', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 8);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Preparar envio',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 5,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_quantity' => 5,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_PREPARED)
            ->assertJsonPath('data.guide.status', InventoryTransferGuide::STATUS_PREPARED)
            ->assertJsonPath('data.guide.checklists.0.status', InventoryTransferChecklist::STATUS_COMPLETED)
            ->assertJsonPath('data.items.0.prepared_quantity', 5)
            ->assertJsonPath('data.items.0.difference_quantity', 0);

        $balance = $this->balance($fromWarehouse, $product);
        $this->assertSame(3.0, (float) $balance->quantity_available);
        $this->assertSame(5.0, (float) $balance->quantity_reserved);
        $this->assertNull($this->balanceOrNull($toWarehouse, $product));
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $fromWarehouse->id,
            'product_id' => $product->id,
            'type' => 'reserved',
            'quantity' => '5.0000',
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transferId,
        ]);
    }

    public function test_preparation_requires_reason_when_quantity_has_difference(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-DIFF', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 8);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 5,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_quantity' => 3,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.difference_reason']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_quantity' => 3,
                    'difference_reason' => 'Faltaron unidades en estante',
                    'difference_notes' => 'Se cargaron solo 3.',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES)
            ->assertJsonPath('data.guide.status', InventoryTransferGuide::STATUS_PREPARED_WITH_DIFFERENCES)
            ->assertJsonPath('data.guide.checklists.0.status', InventoryTransferChecklist::STATUS_COMPLETED_WITH_DIFFERENCES)
            ->assertJsonPath('data.items.0.prepared_quantity', 3)
            ->assertJsonPath('data.items.0.difference_quantity', 2)
            ->assertJsonPath('data.items.0.difference_reason', 'Faltaron unidades en estante');

        $balance = $this->balance($fromWarehouse, $product);
        $this->assertSame(5.0, (float) $balance->quantity_available);
        $this->assertSame(3.0, (float) $balance->quantity_reserved);
    }

    public function test_user_can_prepare_logistic_transfer_with_serialized_units(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-PREP-IMEI', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.view',
        ]);
        $movement = $this->stock($tenant, $fromWarehouse, $product, $user, 3);
        $units = $this->units($tenant, $fromWarehouse, $product, $movement->id, '862000', 3);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_PREPARED)
            ->assertJsonPath('data.items.0.prepared_product_unit_ids.0', $units[0]->id)
            ->assertJsonPath('data.items.0.prepared_quantity', 2);

        $balance = $this->balance($fromWarehouse, $product);
        $this->assertSame(1.0, (float) $balance->quantity_available);
        $this->assertSame(2.0, (float) $balance->quantity_reserved);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[0]->id,
            'warehouse_id' => $fromWarehouse->id,
            'status' => ProductUnit::STATUS_RESERVED,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[2]->id,
            'warehouse_id' => $fromWarehouse->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
    }

    public function test_user_can_dispatch_prepared_logistic_transfer_from_reserved_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-DISP', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 8);

        $transferId = $this->createPreparedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/dispatch", [
                'notes' => 'Carga entregada al transporte.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_DISPATCHED)
            ->assertJsonPath('data.guide.status', InventoryTransferGuide::STATUS_DISPATCHED)
            ->assertJsonPath('data.items.0.prepared_quantity', 5);

        $balance = $this->balance($fromWarehouse, $product);
        $this->assertSame(3.0, (float) $balance->quantity_available);
        $this->assertSame(0.0, (float) $balance->quantity_reserved);
        $this->assertNull($this->balanceOrNull($toWarehouse, $product));
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $fromWarehouse->id,
            'product_id' => $product->id,
            'type' => 'transfer_out',
            'quantity' => '5.0000',
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transferId,
        ]);
    }

    public function test_dispatch_rejects_transfer_that_has_not_been_prepared(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-DISP-BLOCK', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.dispatch',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 8);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 5,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/dispatch")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer']);
    }

    public function test_user_can_dispatch_serialized_logistic_transfer(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-DISP-IMEI', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.view',
        ]);
        $movement = $this->stock($tenant, $fromWarehouse, $product, $user, 3);
        $units = $this->units($tenant, $fromWarehouse, $product, $movement->id, '863000', 3);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/dispatch")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_DISPATCHED);

        $balance = $this->balance($fromWarehouse, $product);
        $this->assertSame(1.0, (float) $balance->quantity_available);
        $this->assertSame(0.0, (float) $balance->quantity_reserved);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[0]->id,
            'warehouse_id' => $fromWarehouse->id,
            'status' => ProductUnit::STATUS_RESERVED,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $fromWarehouse->id,
            'product_id' => $product->id,
            'type' => 'transfer_out',
            'quantity' => '2.0000',
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transferId,
        ]);
    }

    public function test_user_can_receive_dispatched_logistic_transfer_into_destination_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-REC', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 8);

        $transferId = $this->createPreparedAndDispatchedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_quantity' => 5,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED)
            ->assertJsonPath('data.guide.status', InventoryTransferGuide::STATUS_COMPLETED)
            ->assertJsonPath('data.items.0.received_quantity', 5)
            ->assertJsonPath('data.items.0.difference_quantity', 0);

        $originBalance = $this->balance($fromWarehouse, $product);
        $this->assertSame(3.0, (float) $originBalance->quantity_available);
        $this->assertSame(0.0, (float) $originBalance->quantity_reserved);
        $this->assertSame(5.0, (float) $this->balance($toWarehouse, $product)->quantity_available);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $toWarehouse->id,
            'product_id' => $product->id,
            'type' => 'transfer_in',
            'quantity' => '5.0000',
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transferId,
        ]);
        $this->assertDatabaseHas('inventory_transfer_checklists', [
            'tenant_id' => $tenant->id,
            'stage' => InventoryTransferChecklist::STAGE_RECEPTION,
            'status' => InventoryTransferChecklist::STATUS_COMPLETED,
        ]);

        $movementEvents = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'stock_movement.created')
            ->get()
            ->map(fn ($event): array => json_decode($event->payload, true));

        $this->assertContains('reserved', $movementEvents->pluck('type')->all());
        $this->assertContains('transfer_out', $movementEvents->pluck('type')->all());
        $this->assertContains('transfer_in', $movementEvents->pluck('type')->all());
        $this->assertContains($fromWarehouse->code, $movementEvents->pluck('warehouse_code')->all());
        $this->assertContains($toWarehouse->code, $movementEvents->pluck('warehouse_code')->all());
    }

    public function test_receive_rejects_transfer_that_has_not_been_dispatched(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-REC-BLOCK', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.receive',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 8);

        $transferId = $this->createPreparedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_quantity' => 5,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer']);
    }

    public function test_reception_requires_reason_when_quantity_has_difference(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-REC-DIFF', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 8);

        $transferId = $this->createPreparedAndDispatchedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_quantity' => 3,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.difference_reason']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_quantity' => 3,
                    'difference_reason' => 'Llegaron menos unidades',
                    'difference_notes' => 'Transporte reporto faltante.',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES)
            ->assertJsonPath('data.guide.status', InventoryTransferGuide::STATUS_COMPLETED_WITH_DIFFERENCES)
            ->assertJsonPath('data.items.0.received_quantity', 3)
            ->assertJsonPath('data.items.0.difference_quantity', 2)
            ->assertJsonPath('data.items.0.difference_reason', 'Llegaron menos unidades');

        $this->assertSame(3.0, (float) $this->balance($toWarehouse, $product)->quantity_available);
        $this->assertDatabaseHas('inventory_transfer_checklists', [
            'tenant_id' => $tenant->id,
            'stage' => InventoryTransferChecklist::STAGE_RECEPTION,
            'status' => InventoryTransferChecklist::STATUS_COMPLETED_WITH_DIFFERENCES,
        ]);
    }

    public function test_user_can_receive_serialized_logistic_transfer_with_imeis(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-REC-IMEI', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.view',
        ]);
        $movement = $this->stock($tenant, $fromWarehouse, $product, $user, 3);
        $units = $this->units($tenant, $fromWarehouse, $product, $movement->id, '864000', 3);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/dispatch")
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED)
            ->assertJsonPath('data.items.0.received_quantity', 2)
            ->assertJsonPath('data.items.0.received_product_unit_ids.1', $units[1]->id);

        $this->assertSame(1.0, (float) $this->balance($fromWarehouse, $product)->quantity_available);
        $this->assertSame(0.0, (float) $this->balance($fromWarehouse, $product)->quantity_reserved);
        $this->assertSame(2.0, (float) $this->balance($toWarehouse, $product)->quantity_available);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[0]->id,
            'warehouse_id' => $toWarehouse->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[2]->id,
            'warehouse_id' => $fromWarehouse->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);

        $unitEvents = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'product_unit.updated')
            ->get()
            ->map(fn ($event): array => json_decode($event->payload, true));

        $this->assertGreaterThanOrEqual(6, $unitEvents->count());
        $this->assertContains($units[0]->serial_number, $unitEvents->pluck('serial_number')->all());
        $this->assertContains($toWarehouse->code, $unitEvents->pluck('warehouse_code')->all());
    }

    public function test_serialized_transfer_rejects_wrong_or_unavailable_units(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-BLOCK', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['inventory_transfers.create']);
        $movement = $this->stock($tenant, $fromWarehouse, $product, $user, 1);
        $unit = $this->units($tenant, $fromWarehouse, $product, $movement->id, '861001', 1)[0];
        $unit->update(['status' => ProductUnit::STATUS_SOLD]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'product_unit_ids' => [$unit->id],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_unit_ids.0']);
    }

    public function test_transfer_rejects_more_than_available_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-OVER', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['inventory_transfers.create']);
        $this->stock($tenant, $fromWarehouse, $product, $user, 1);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertUnprocessable();
    }

    public function test_transfers_do_not_mix_companies_and_reject_foreign_resources(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$fromA, $toA, $productA] = $this->warehousesAndProduct($tenantA, 'TRF-A', Product::TRACKING_QUANTITY);
        [$fromB, $toB, $productB] = $this->warehousesAndProduct($tenantB, 'TRF-B', Product::TRACKING_QUANTITY);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Almacen A', ['inventory_transfers.create', 'inventory_transfers.view']);
        $this->grantRole($tenantB, $userB, 'Almacen B', ['inventory_transfers.create', 'inventory_transfers.view']);
        $this->stock($tenantA, $fromA, $productA, $userA, 2);
        $this->stock($tenantB, $fromB, $productB, $userB, 2);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromA->id,
                'to_warehouse_id' => $toA->id,
                'reason' => 'A',
                'items' => [[
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromB->id,
                'to_warehouse_id' => $toB->id,
                'reason' => 'B',
                'items' => [[
                    'product_id' => $productB->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/inventory-transfers')
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'A')
            ->assertJsonMissing(['reason' => 'B']);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromA->id,
                'to_warehouse_id' => $toB->id,
                'items' => [[
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_warehouse_id']);
    }

    public function test_transfer_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-NOAUTH', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertForbidden();
    }

    public function test_user_can_cancel_requested_logistic_transfer_without_affecting_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-CXL-REQ', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.cancel',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Solicitud inicial',
                'items' => [['product_id' => $product->id, 'quantity' => 4]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/cancel", [
                'cancellation_reason' => 'Cliente cancelo el pedido antes de preparar.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_CANCELLED)
            ->assertJsonPath('data.cancelled_by', $user->id);

        $this->assertNotNull(InventoryTransfer::find($transferId)->cancelled_at);
        $balance = $this->balance($fromWarehouse, $product);
        $this->assertSame(10.0, (float) $balance->quantity_available);
        $this->assertSame(0.0, (float) $balance->quantity_reserved);
        $this->assertDatabaseMissing('stock_movements', [
            'tenant_id' => $tenant->id,
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transferId,
            'type' => 'released',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'inventory_transfer.cancelled',
            'entity_type' => InventoryTransfer::class,
            'entity_id' => $transferId,
        ]);
    }

    public function test_user_can_cancel_prepared_logistic_transfer_and_release_reserved_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-CXL-PREP', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.cancel',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $transferId = $this->createPreparedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);

        $balance = $this->balance($fromWarehouse, $product);
        $this->assertSame(5.0, (float) $balance->quantity_available);
        $this->assertSame(5.0, (float) $balance->quantity_reserved);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/cancel", [
                'cancellation_reason' => 'Reprogramamos la entrega para la proxima semana.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_CANCELLED);

        $balance = $this->balance($fromWarehouse, $product);
        $this->assertSame(10.0, (float) $balance->quantity_available);
        $this->assertSame(0.0, (float) $balance->quantity_reserved);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transferId,
            'type' => 'released',
            'quantity' => '5.0000',
        ]);
    }

    public function test_user_can_cancel_prepared_with_differences_logistic_transfer(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-CXL-PD', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.cancel',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [['product_id' => $product->id, 'quantity' => 6]],
            ])
            ->assertCreated()
            ->json('data.id');
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_quantity' => 4,
                    'difference_reason' => 'Faltaron unidades en estante',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/cancel", [
                'cancellation_reason' => 'El supervisor decidio reevaluar la operacion.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_CANCELLED);

        $balance = $this->balance($fromWarehouse, $product);
        $this->assertSame(10.0, (float) $balance->quantity_available);
        $this->assertSame(0.0, (float) $balance->quantity_reserved);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transferId,
            'type' => 'released',
            'quantity' => '4.0000',
        ]);
    }

    public function test_user_cannot_cancel_dispatched_logistic_transfer(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-CXL-DISP', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.cancel',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $transferId = $this->createPreparedAndDispatchedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/cancel", [
                'cancellation_reason' => 'Quiero cancelar aun despues del despacho.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $transfer = InventoryTransfer::find($transferId);
        $this->assertSame(InventoryTransfer::STATUS_DISPATCHED, $transfer->status);
        $this->assertNull($transfer->cancelled_at);
    }

    public function test_user_cannot_cancel_completed_logistic_transfer(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-CXL-COMP', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.cancel',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $transferId = $this->createPreparedAndDispatchedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [['inventory_transfer_item_id' => $itemId, 'received_quantity' => 5]],
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/cancel", [
                'cancellation_reason' => 'Intento cancelar un traslado ya recibido.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_user_cannot_cancel_simple_transfer(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-CXL-SIMP', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.cancel',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 5);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/cancel", [
                'cancellation_reason' => 'No deberia poder cancelar un traslado simple.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer']);
    }

    public function test_cancel_requires_cancellation_reason(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-CXL-NORSN', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.cancel',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 5);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/cancel", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cancellation_reason']);
    }

    public function test_cancel_releases_serialized_units_back_to_available(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-CXL-IMEI', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.cancel',
            'inventory_transfers.view',
        ]);
        $movement = $this->stock($tenant, $fromWarehouse, $product, $user, 3);
        $units = $this->units($tenant, $fromWarehouse, $product, $movement->id, '865000', 3);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[0]->id,
            'status' => ProductUnit::STATUS_RESERVED,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/cancel", [
                'cancellation_reason' => 'Cliente cambio de opinion, devolvemos inventario.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_CANCELLED);

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[0]->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
            'released_stock_movement_id' => null,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[2]->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
    }

    public function test_cancel_emits_sync_outbox_events_for_released_stock_and_units(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-CXL-SYNC', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.cancel',
            'inventory_transfers.view',
        ]);
        $movement = $this->stock($tenant, $fromWarehouse, $product, $user, 3);
        $units = $this->units($tenant, $fromWarehouse, $product, $movement->id, '866000', 3);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertOk();

        DB::table('sync_outbox')->where('tenant_id', $tenant->id)->delete();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/cancel", [
                'cancellation_reason' => 'Verificamos emision de eventos sync al cancelar.',
            ])
            ->assertOk();

        $movementEvents = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'stock_movement.created')
            ->get()
            ->map(fn ($event): array => json_decode($event->payload, true));

        $this->assertContains('released', $movementEvents->pluck('type')->all());
        $this->assertContains($fromWarehouse->code, $movementEvents->pluck('warehouse_code')->all());

        $unitEvents = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'product_unit.updated')
            ->get()
            ->map(fn ($event): array => json_decode($event->payload, true));

        $this->assertGreaterThanOrEqual(2, $unitEvents->count());
        $this->assertContains($units[0]->serial_number, $unitEvents->pluck('serial_number')->all());
    }

    public function test_cancel_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-CXL-AUTH', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 5);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/cancel", [
                'cancellation_reason' => 'No tengo permiso para cancelar.',
            ])
            ->assertForbidden();
    }

    public function test_cancel_isolated_per_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$fromA, $toA, $productA] = $this->warehousesAndProduct($tenantA, 'TRF-A', Product::TRACKING_QUANTITY);
        [$fromB, $toB, $productB] = $this->warehousesAndProduct($tenantB, 'TRF-B', Product::TRACKING_QUANTITY);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.cancel',
            'inventory_transfers.view',
        ]);
        $this->grantRole($tenantB, $userB, 'Almacen B', [
            'inventory_transfers.create',
            'inventory_transfers.cancel',
            'inventory_transfers.view',
        ]);
        $this->stock($tenantA, $fromA, $productA, $userA, 5);
        $this->stock($tenantB, $fromB, $productB, $userB, 5);

        $transferAId = $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromA->id,
                'to_warehouse_id' => $toA->id,
                'items' => [['product_id' => $productA->id, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson("/api/inventory-transfers/{$transferAId}/cancel", [
                'cancellation_reason' => 'No debo poder cancelar traslados de otra empresa.',
            ])
            ->assertForbidden();

        $this->assertSame(InventoryTransfer::STATUS_REQUESTED, InventoryTransfer::find($transferAId)->status);
    }

    public function test_user_can_resolve_differences_with_accept_loss_and_adjust_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-RES-LOSS', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.resolve_differences',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $transferId = $this->createPreparedAndDispatchedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_quantity' => 3,
                    'difference_reason' => 'Llegaron 2 unidades menos de lo esperado.',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/resolve-differences", [
                'notes' => 'Tras auditoria se confirman 2 unidades perdidas en transito.',
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'action' => InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS,
                    'notes' => 'Robo parcial en el transporte.',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED)
            ->assertJsonPath('data.resolution_status', InventoryTransfer::RESOLUTION_RESOLVED)
            ->assertJsonPath('data.items.0.resolution_status', InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS);

        $balance = $this->balance($toWarehouse, $product);
        $this->assertSame(3.0, (float) $balance->quantity_available);
        $this->assertDatabaseMissing('stock_movements', [
            'tenant_id' => $tenant->id,
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transferId,
            'type' => 'adjustment_out',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'inventory_transfer.differences_resolved',
            'entity_type' => InventoryTransfer::class,
            'entity_id' => $transferId,
        ]);
    }

    public function test_user_can_resolve_differences_with_investigate_and_keep_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-RES-INV', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.resolve_differences',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $transferId = $this->createPreparedAndDispatchedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_quantity' => 2,
                    'difference_reason' => 'Tres unidades pendientes de verificacion.',
                ]],
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/resolve-differences", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'action' => InventoryTransferItem::RESOLUTION_INVESTIGATING,
                    'notes' => 'Coordinando con el transportista para confirmar el faltante.',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES)
            ->assertJsonPath('data.resolution_status', InventoryTransfer::RESOLUTION_PARTIAL)
            ->assertJsonPath('data.items.0.resolution_status', InventoryTransferItem::RESOLUTION_INVESTIGATING);

        $balance = $this->balance($toWarehouse, $product);
        $this->assertSame(2.0, (float) $balance->quantity_available);
        $this->assertDatabaseMissing('stock_movements', [
            'tenant_id' => $tenant->id,
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transferId,
            'type' => 'adjustment_out',
        ]);
    }

    public function test_user_can_resolve_differences_with_manual_adjustment_and_custom_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-RES-MAN', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.resolve_differences',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $transferId = $this->createPreparedAndDispatchedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_quantity' => 4,
                    'difference_reason' => 'Una unidad pendiente.',
                ]],
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/resolve-differences", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'action' => InventoryTransferItem::RESOLUTION_ADJUSTED_MANUALLY,
                    'quantity' => 1.5,
                    'notes' => 'El supervisor decidio registrar 1.5 unidades como merma.',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.resolution_status', InventoryTransfer::RESOLUTION_RESOLVED)
            ->assertJsonPath('data.items.0.resolution_status', InventoryTransferItem::RESOLUTION_ADJUSTED_MANUALLY);

        $balance = $this->balance($toWarehouse, $product);
        $this->assertSame(2.5, (float) $balance->quantity_available);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transferId,
            'type' => 'adjustment_out',
            'quantity' => '1.5000',
        ]);
    }

    public function test_user_can_resolve_differences_with_mixed_actions_and_partial_status(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $this->useTenant($tenant);
        $branch = Branch::create(['name' => 'Sucursal MIX', 'code' => 'MIX']);
        $fromWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen MIX', 'code' => 'FROM-MIX']);
        $toWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino MIX', 'code' => 'TO-MIX']);
        $productA = Product::create([
            'name' => 'Producto MIX A',
            'sku' => 'MIX-A',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 50,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $productB = Product::create([
            'name' => 'Producto MIX B',
            'sku' => 'MIX-B',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 80,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.resolve_differences',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $productA, $user, 10);
        $this->stock($tenant, $fromWarehouse, $productB, $user, 10);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [
                    ['product_id' => $productA->id, 'quantity' => 4],
                    ['product_id' => $productB->id, 'quantity' => 4],
                ],
            ])
            ->assertCreated()
            ->json('data.id');
        $itemAId = InventoryTransfer::query()->findOrFail($transferId)->items()->where('product_id', $productA->id)->firstOrFail()->id;
        $itemBId = InventoryTransfer::query()->findOrFail($transferId)->items()->where('product_id', $productB->id)->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [
                    ['inventory_transfer_item_id' => $itemAId, 'prepared_quantity' => 4],
                    ['inventory_transfer_item_id' => $itemBId, 'prepared_quantity' => 4],
                ],
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/dispatch")
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [
                    ['inventory_transfer_item_id' => $itemAId, 'received_quantity' => 2, 'difference_reason' => 'Faltaron 2.'],
                    ['inventory_transfer_item_id' => $itemBId, 'received_quantity' => 3, 'difference_reason' => 'Falto 1.'],
                ],
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/resolve-differences", [
                'items' => [
                    ['inventory_transfer_item_id' => $itemAId, 'action' => InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS, 'notes' => 'Perdida total.'],
                    ['inventory_transfer_item_id' => $itemBId, 'action' => InventoryTransferItem::RESOLUTION_INVESTIGATING, 'notes' => 'Por verificar.'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES)
            ->assertJsonPath('data.resolution_status', InventoryTransfer::RESOLUTION_PARTIAL);

        $transfer = InventoryTransfer::find($transferId);
        $itemA = $transfer->items->firstWhere('id', $itemAId);
        $itemB = $transfer->items->firstWhere('id', $itemBId);
        $this->assertSame(InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS, $itemA->resolution_status);
        $this->assertSame(InventoryTransferItem::RESOLUTION_INVESTIGATING, $itemB->resolution_status);
        $this->assertNull($transfer->resolved_at);
    }

    public function test_user_can_resolve_differences_and_marks_missing_serial_units_as_removed(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-RES-IMEI', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.resolve_differences',
            'inventory_transfers.view',
        ]);
        $movement = $this->stock($tenant, $fromWarehouse, $product, $user, 3);
        $units = $this->units($tenant, $fromWarehouse, $product, $movement->id, '867000', 3);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'product_unit_ids' => [$units[0]->id, $units[1]->id, $units[2]->id],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_product_unit_ids' => [$units[0]->id, $units[1]->id, $units[2]->id],
                ]],
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/dispatch")
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_product_unit_ids' => [$units[0]->id],
                    'difference_reason' => 'No llegaron 2 unidades.',
                ]],
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/resolve-differences", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'action' => InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS,
                    'notes' => 'Las unidades faltantes se reportan como robadas.',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.resolution_status', InventoryTransfer::RESOLUTION_RESOLVED);

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[1]->id,
            'status' => ProductUnit::STATUS_REMOVED,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[2]->id,
            'status' => ProductUnit::STATUS_REMOVED,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[0]->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
            'warehouse_id' => $toWarehouse->id,
        ]);
    }

    public function test_user_cannot_resolve_transfer_without_differences(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-RES-NODIF', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.resolve_differences',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $transferId = $this->createPreparedAndDispatchedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_quantity' => 5,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/resolve-differences", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'action' => InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_user_cannot_resolve_transfer_that_is_not_completed_with_differences(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-RES-WRONG', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.resolve_differences',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 5);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('data.id');
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/resolve-differences", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'action' => InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_resolve_differences_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-RES-AUTH', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $transferId = $this->createPreparedAndDispatchedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_quantity' => 3,
                    'difference_reason' => 'Faltaron 2.',
                ]],
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/resolve-differences", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'action' => InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS,
                ]],
            ])
            ->assertForbidden();
    }

    public function test_resolve_differences_emits_sync_outbox_events(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-RES-SYNC', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', [
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.resolve_differences',
            'inventory_transfers.view',
        ]);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $transferId = $this->createPreparedAndDispatchedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, 5);
        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_quantity' => 4,
                    'difference_reason' => 'Falto 1 unidad.',
                ]],
            ])
            ->assertOk();

        DB::table('sync_outbox')->where('tenant_id', $tenant->id)->delete();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/resolve-differences", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'action' => InventoryTransferItem::RESOLUTION_ADJUSTED_MANUALLY,
                    'quantity' => 1.5,
                    'notes' => 'El supervisor decidio registrar 1.5 unidades como merma.',
                ]],
            ])
            ->assertOk();

        $movementEvents = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'stock_movement.created')
            ->get()
            ->map(fn ($event): array => json_decode($event->payload, true));

        $this->assertContains('adjustment_out', $movementEvents->pluck('type')->all());
        $this->assertContains($toWarehouse->code, $movementEvents->pluck('warehouse_code')->all());
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function warehousesAndProduct(Tenant $tenant, string $sku, string $trackingType): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$sku}", 'code' => "BR-{$sku}"]);
        $fromWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Origen {$sku}", 'code' => "FROM-{$sku}"]);
        $toWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Destino {$sku}", 'code' => "TO-{$sku}"]);
        $product = Product::create([
            'name' => "Producto {$sku}",
            'sku' => $sku,
            'tracking_type' => $trackingType,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$fromWarehouse, $toWarehouse, $product];
    }

    private function stock(Tenant $tenant, Warehouse $warehouse, Product $product, User $user, float $quantity)
    {
        $this->useTenant($tenant);

        return app(InventoryMovementService::class)->purchase(
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: 50,
            createdBy: $user,
            reason: "Stock prueba {$product->sku}",
        );
    }

    private function createPreparedTransfer(
        Tenant $tenant,
        User $user,
        Warehouse $fromWarehouse,
        Warehouse $toWarehouse,
        Product $product,
        float $quantity,
    ): int {
        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Despacho con guia',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $itemId = InventoryTransfer::query()->findOrFail($transferId)->items()->firstOrFail()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_quantity' => $quantity,
                ]],
            ])
            ->assertOk();

        return $transferId;
    }

    private function createPreparedAndDispatchedTransfer(
        Tenant $tenant,
        User $user,
        Warehouse $fromWarehouse,
        Warehouse $toWarehouse,
        Product $product,
        float $quantity,
    ): int {
        $transferId = $this->createPreparedTransfer($tenant, $user, $fromWarehouse, $toWarehouse, $product, $quantity);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/dispatch")
            ->assertOk();

        return $transferId;
    }

    private function units(Tenant $tenant, Warehouse $warehouse, Product $product, int $movementId, string $prefix, int $quantity): array
    {
        $this->useTenant($tenant);
        $units = [];

        foreach (range(1, $quantity) as $index) {
            $units[] = ProductUnit::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                'serial_number' => $prefix.str_pad((string) $index, 9, '0', STR_PAD_LEFT),
                'status' => ProductUnit::STATUS_AVAILABLE,
                'acquired_stock_movement_id' => $movementId,
            ]);
        }

        return $units;
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

    private function balance(Warehouse $warehouse, Product $product): StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    private function balanceOrNull(Warehouse $warehouse, Product $product): ?StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->first();
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
