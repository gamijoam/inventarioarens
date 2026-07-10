<?php

namespace Tests\Feature\AdminPortal;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Models\InventoryTransferItem;
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

class AdminTransferActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_transfer_detail(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Detalle', 'slug' => 'empresa-detalle']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-DET');
        $this->stock($tenant, $from, $product, 10);

        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 4);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/transfers/'.$transfer->id)
            ->assertOk()
            ->assertJsonPath('data.transfer.id', $transfer->id)
            ->assertJsonPath('data.transfer.status', InventoryTransfer::STATUS_REQUESTED)
            ->assertJsonPath('data.transfer.from_warehouse_id', $from->id)
            ->assertJsonPath('data.transfer.to_warehouse_id', $to->id);

        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('prepare', $response->json('data.available_actions.0'));
        $this->assertSame('cancel', $response->json('data.available_actions.1'));
    }

    public function test_view_detail_requires_admin_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Detalle 403', 'slug' => 'empresa-detalle-403']);
        $user = $this->userInTenant($tenant, ['inventory_transfers.view', 'inventory_transfers.create']);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-DET-403');
        $this->stock($tenant, $from, $product, 5);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 1);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/transfers/'.$transfer->id)
            ->assertForbidden();
    }

    public function test_view_detail_returns_404_for_other_tenant(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);

        $this->useTenant($tenantA);
        [$fromA, $toA, $productA] = $this->warehousesAndProduct($tenantA, 'TR-A');
        $this->stock($tenantA, $fromA, $productA, 5);
        $transfer = $this->createLogisticTransferViaApi($userA, $tenantA, $fromA, $toA, $productA, 2);

        $userB = $this->userInTenant($tenantB);

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/admin-portal/transfers/'.$transfer->id)
            ->assertNotFound();
    }

    public function test_admin_can_prepare_transfer_via_admin_portal(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Prep', 'slug' => 'empresa-prep']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-PREP');
        $this->stock($tenant, $from, $product, 10);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 4);

        $item = $transfer->items->first();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/prepare', [
                'notes' => 'Preparacion OK',
                'items' => [[
                    'inventory_transfer_item_id' => $item->id,
                    'prepared_quantity' => 4,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_PREPARED);

        $transfer->refresh();
        $this->assertSame(InventoryTransfer::STATUS_PREPARED, $transfer->status);
        $this->assertSame(4.0, (float) $transfer->items->first()->prepared_quantity);
    }

    public function test_prepare_requires_specific_prepare_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Prep 403', 'slug' => 'empresa-prep-403']);
        $user = $this->userInTenant($tenant, [
            'inventory_transfers.admin',
            'inventory_transfers.view',
            'inventory_transfers.create',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.cancel',
        ]);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-PREP-403');
        $this->stock($tenant, $from, $product, 5);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 2);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/prepare', [
                'items' => [['inventory_transfer_item_id' => $transfer->items->first()->id, 'prepared_quantity' => 2]],
            ])
            ->assertForbidden();
    }

    public function test_admin_can_dispatch_transfer_via_admin_portal(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Disp', 'slug' => 'empresa-disp']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-DISP');
        $this->stock($tenant, $from, $product, 10);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 3);
        $this->prepareTransfer($user, $tenant, $transfer);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/dispatch', [
                'notes' => 'Despachado',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_DISPATCHED);
    }

    public function test_dispatch_requires_specific_dispatch_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Disp 403', 'slug' => 'empresa-disp-403']);
        $user = $this->userInTenant($tenant, [
            'inventory_transfers.admin',
            'inventory_transfers.view',
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.receive',
            'inventory_transfers.cancel',
        ]);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-DISP-403');
        $this->stock($tenant, $from, $product, 5);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 2);
        $this->prepareTransfer($user, $tenant, $transfer);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/dispatch', [])
            ->assertForbidden();
    }

    public function test_admin_can_receive_transfer_via_admin_portal(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Recv', 'slug' => 'empresa-recv']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-RECV');
        $this->stock($tenant, $from, $product, 10);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 3);
        $this->prepareTransfer($user, $tenant, $transfer);
        $this->dispatchTransfer($user, $tenant, $transfer);

        $item = $transfer->refresh()->items->first();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/receive', [
                'items' => [[
                    'inventory_transfer_item_id' => $item->id,
                    'received_quantity' => 3,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED);
    }

    public function test_receive_requires_specific_receive_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Recv 403', 'slug' => 'empresa-recv-403']);
        $user = $this->userInTenant($tenant, [
            'inventory_transfers.admin',
            'inventory_transfers.view',
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.cancel',
        ]);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-RECV-403');
        $this->stock($tenant, $from, $product, 5);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 2);
        $this->prepareTransfer($user, $tenant, $transfer);
        $this->dispatchTransfer($user, $tenant, $transfer);

        $item = $transfer->refresh()->items->first();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/receive', [
                'items' => [['inventory_transfer_item_id' => $item->id, 'received_quantity' => 2]],
            ])
            ->assertForbidden();
    }

    public function test_admin_can_cancel_transfer_via_admin_portal(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Canc', 'slug' => 'empresa-canc']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-CANC');
        $this->stock($tenant, $from, $product, 5);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 2);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/cancel', [
                'cancellation_reason' => 'Cliente cancelo el pedido',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_CANCELLED);

        $transfer->refresh();
        $this->assertNotNull($transfer->cancelled_at);
    }

    public function test_cancel_requires_specific_cancel_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Canc 403', 'slug' => 'empresa-canc-403']);
        $user = $this->userInTenant($tenant, [
            'inventory_transfers.admin',
            'inventory_transfers.view',
            'inventory_transfers.create',
            'inventory_transfers.prepare',
        ]);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-CANC-403');
        $this->stock($tenant, $from, $product, 5);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 1);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/cancel', [
                'cancellation_reason' => 'No se debe procesar',
            ])
            ->assertForbidden();
    }

    public function test_cancel_requires_reason_min_length(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Canc 422', 'slug' => 'empresa-canc-422']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-CANC-422');
        $this->stock($tenant, $from, $product, 5);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 1);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/cancel', [
                'cancellation_reason' => 'no',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cancellation_reason']);
    }

    public function test_detail_includes_audit_log_with_event_chain(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Audit', 'slug' => 'empresa-audit']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-AUDIT');
        $this->stock($tenant, $from, $product, 10);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 4);
        $this->prepareTransfer($user, $tenant, $transfer);
        $this->dispatchTransfer($user, $tenant, $transfer);

        $item = $transfer->refresh()->items->first();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/admin-portal/transfers/{$transfer->id}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $item->id,
                    'received_quantity' => 3,
                    'difference_reason' => 'Faltante en transito',
                ]],
            ])
            ->assertOk();

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/admin-portal/transfers/{$transfer->id}")
            ->assertOk();

        $audit = $response->json('data.audit');
        $this->assertIsArray($audit);
        $this->assertNotEmpty($audit, 'El detail debe incluir al menos un evento de audit');

        $actions = array_column($audit, 'action');
        $this->assertContains('inventory_transfer.created', $actions);
        $this->assertContains('inventory_transfer.prepared', $actions);
        $this->assertContains('inventory_transfer.dispatched', $actions);
        $this->assertContains('inventory_transfer.received', $actions);

        // El mas reciente primero
        $this->assertSame('inventory_transfer.received', $audit[0]['action']);

        // Cada evento tiene user + new_values
        foreach ($audit as $event) {
            $this->assertNotNull($event['user']);
            $this->assertSame($user->id, $event['user']['id']);
            $this->assertIsArray($event['new_values']);
        }
    }

    public function test_detail_audit_does_not_leak_other_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b']);
        $userA = $this->userInTenant($tenantA);

        $this->useTenant($tenantA);
        [$fromA, $toA, $productA] = $this->warehousesAndProduct($tenantA, 'TR-ISO-A');
        $this->stock($tenantA, $fromA, $productA, 5);
        $transferA = $this->createLogisticTransferViaApi($userA, $tenantA, $fromA, $toA, $productA, 2);

        $userB = $this->userInTenant($tenantB);
        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson("/api/admin-portal/transfers/{$transferA->id}")
            ->assertNotFound();
    }

    public function test_admin_can_resolve_differences_via_admin_portal(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa Res', 'slug' => 'empresa-res']);
        $user = $this->userInTenant($tenant);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-RES');
        $this->stock($tenant, $from, $product, 10);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 4);

        $item = $transfer->items->first();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/prepare', [
                'items' => [[
                    'inventory_transfer_item_id' => $item->id,
                    'prepared_quantity' => 3,
                    'difference_reason' => 'Sin stock',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES);

        $this->dispatchTransfer($user, $tenant, $transfer);

        $item = $transfer->refresh()->items->first();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/receive', [
                'items' => [[
                    'inventory_transfer_item_id' => $item->id,
                    'received_quantity' => 2,
                    'difference_reason' => 'Dano en transporte',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES);

        $item = $transfer->refresh()->items->first();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/resolve-differences', [
                'items' => [[
                    'inventory_transfer_item_id' => $item->id,
                    'action' => InventoryTransferItem::RESOLUTION_INVESTIGATING,
                    'notes' => 'Pendiente de auditoria',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES);
    }

    public function test_resolve_differences_requires_specific_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Res 403', 'slug' => 'empresa-res-403']);
        $user = $this->userInTenant($tenant, [
            'inventory_transfers.admin',
            'inventory_transfers.view',
            'inventory_transfers.create',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
            'inventory_transfers.cancel',
        ]);
        $this->useTenant($tenant);
        [$from, $to, $product] = $this->warehousesAndProduct($tenant, 'TR-RES-403');
        $this->stock($tenant, $from, $product, 5);
        $transfer = $this->createLogisticTransferViaApi($user, $tenant, $from, $to, $product, 2);
        $transfer->update(['status' => InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES]);

        $item = $transfer->items->first();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/resolve-differences', [
                'items' => [[
                    'inventory_transfer_item_id' => $item->id,
                    'action' => InventoryTransferItem::RESOLUTION_INVESTIGATING,
                ]],
            ])
            ->assertForbidden();
    }

    public function test_prepare_action_rejects_cross_tenant_user(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);

        $this->useTenant($tenantA);
        [$fromA, $toA, $productA] = $this->warehousesAndProduct($tenantA, 'TR-XP-A');
        $this->stock($tenantA, $fromA, $productA, 5);
        $transfer = $this->createLogisticTransferViaApi($userA, $tenantA, $fromA, $toA, $productA, 2);

        $item = $transfer->items->first();

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/prepare', [
                'items' => [['inventory_transfer_item_id' => $item->id, 'prepared_quantity' => 2]],
            ])
            ->assertForbidden();

        $transfer->refresh();
        $this->assertSame(InventoryTransfer::STATUS_REQUESTED, $transfer->status);
    }

    public function test_dispatch_action_rejects_cross_tenant_user(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);

        $this->useTenant($tenantA);
        [$fromA, $toA, $productA] = $this->warehousesAndProduct($tenantA, 'TR-XD-A');
        $this->stock($tenantA, $fromA, $productA, 5);
        $transfer = $this->createLogisticTransferViaApi($userA, $tenantA, $fromA, $toA, $productA, 2);
        $this->prepareTransfer($userA, $tenantA, $transfer);

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/dispatch', [])
            ->assertForbidden();

        $transfer->refresh();
        $this->assertSame(InventoryTransfer::STATUS_PREPARED, $transfer->status);
    }

    public function test_receive_action_rejects_cross_tenant_user(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);

        $this->useTenant($tenantA);
        [$fromA, $toA, $productA] = $this->warehousesAndProduct($tenantA, 'TR-XR-A');
        $this->stock($tenantA, $fromA, $productA, 5);
        $transfer = $this->createLogisticTransferViaApi($userA, $tenantA, $fromA, $toA, $productA, 2);
        $this->prepareTransfer($userA, $tenantA, $transfer);
        $this->dispatchTransfer($userA, $tenantA, $transfer);

        $item = $transfer->refresh()->items->first();

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/receive', [
                'items' => [['inventory_transfer_item_id' => $item->id, 'received_quantity' => 2]],
            ])
            ->assertForbidden();

        $transfer->refresh();
        $this->assertSame(InventoryTransfer::STATUS_DISPATCHED, $transfer->status);
    }

    public function test_cancel_action_rejects_cross_tenant_user(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);

        $this->useTenant($tenantA);
        [$fromA, $toA, $productA] = $this->warehousesAndProduct($tenantA, 'TR-XC-A');
        $this->stock($tenantA, $fromA, $productA, 5);
        $transfer = $this->createLogisticTransferViaApi($userA, $tenantA, $fromA, $toA, $productA, 2);

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/cancel', [
                'cancellation_reason' => 'Intento cross-tenant',
            ])
            ->assertForbidden();

        $transfer->refresh();
        $this->assertNotSame(InventoryTransfer::STATUS_CANCELLED, $transfer->status);
    }

    public function test_resolve_differences_action_rejects_cross_tenant_user(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);

        $this->useTenant($tenantA);
        [$fromA, $toA, $productA] = $this->warehousesAndProduct($tenantA, 'TR-XV-A');
        $this->stock($tenantA, $fromA, $productA, 5);
        $transfer = $this->createLogisticTransferViaApi($userA, $tenantA, $fromA, $toA, $productA, 2);
        $transfer->update(['status' => InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES]);

        $item = $transfer->items->first();

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/resolve-differences', [
                'items' => [[
                    'inventory_transfer_item_id' => $item->id,
                    'action' => InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS,
                ]],
            ])
            ->assertForbidden();

        $transfer->refresh();
        $this->assertSame(InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES, $transfer->status);
    }

    public function test_user_without_X_Tenant_header_is_rejected(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa NoHeader', 'slug' => 'empresa-noheader']);
        $user = $this->userInTenant($tenant);

        // Sin X-Tenant el TenantManager no resuelve tenant actual => el query
        // base filtrado no devuelve nada => 404 (mismo patron que el resto
        // de los endpoints cross-tenant, intencional para no leakear
        // existencia de recursos).
        $this
            ->actingAs($user)
            ->getJson('/api/admin-portal/transfers')
            ->assertNotFound();
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

    private function createLogisticTransferViaApi(User $user, Tenant $tenant, Warehouse $from, Warehouse $to, Product $product, float $quantity): InventoryTransfer
    {
        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'reason' => 'Traslado test logistic',
                'reference' => 'REFL-'.$product->sku,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ]],
            ]);

        $response->assertCreated();

        $id = $response->json('data.id');

        return InventoryTransfer::findOrFail($id);
    }

    private function prepareTransfer(User $user, Tenant $tenant, InventoryTransfer $transfer): void
    {
        $transfer = $transfer->refresh();
        $payload = $transfer->items->map(fn (InventoryTransferItem $item): array => [
            'inventory_transfer_item_id' => $item->id,
            'prepared_quantity' => (float) ($item->requested_quantity ?? $item->quantity),
        ])->all();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/prepare', [
                'items' => $payload,
            ])
            ->assertOk();
    }

    private function dispatchTransfer(User $user, Tenant $tenant, InventoryTransfer $transfer): void
    {
        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/admin-portal/transfers/'.$transfer->id.'/dispatch', [])
            ->assertOk();
    }
}
