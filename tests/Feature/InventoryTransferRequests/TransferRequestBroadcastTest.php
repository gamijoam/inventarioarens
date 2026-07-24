<?php

namespace Tests\Feature\InventoryTransferRequests;

use App\Models\User;
use App\Modules\InventoryTransferRequests\Events\TransferRequestAccepted;
use App\Modules\InventoryTransferRequests\Events\TransferRequestCancelled;
use App\Modules\InventoryTransferRequests\Events\TransferRequestCreated;
use App\Modules\InventoryTransferRequests\Events\TransferRequestRejected;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TransferRequestBroadcastTest extends TestCase
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

    public function test_creating_transfer_request_dispatches_broadcast_event(): void
    {
        Event::fake([TransferRequestCreated::class]);

        [$group, $spinoff] = $this->createGroupAndSpinoff();
        $admin = $this->createAdmin($group, ['inventory_transfer_requests.create']);
        $warehouse = $this->createWarehouse($group);
        $product = $this->createProduct($group);

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant', $group->slug)
            ->postJson('/api/inventory-transfer-requests', [
                'destination_tenant_slug' => $spinoff->slug,
                'from_warehouse_id' => $warehouse->id,
                'destination_warehouse_id' => $warehouse->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]);

        $response->assertCreated();
        Event::assertDispatched(TransferRequestCreated::class, function (TransferRequestCreated $event) use ($spinoff) {
            return $event->destinationTenantId === $spinoff->id;
        });
    }

    public function test_broadcast_event_uses_private_channel_for_destination_tenant(): void
    {
        [$group, $spinoff] = $this->createGroupAndSpinoff();
        $transferRequest = $this->makeRequestFor($group, $spinoff);
        $event = TransferRequestCreated::fromModel($transferRequest);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-tenant.'.$spinoff->id, $channels[0]->name);
    }

    public function test_broadcast_event_payload_excludes_sensitive_data(): void
    {
        [$group, $spinoff] = $this->createGroupAndSpinoff();
        $transferRequest = $this->makeRequestFor($group, $spinoff);
        $event = TransferRequestCreated::fromModel($transferRequest);

        $payload = $event->broadcastWith();

        $this->assertSame($transferRequest->id, $payload['id']);
        $this->assertSame($group->id, $payload['origin_tenant_id']);
        $this->assertSame($spinoff->id, $payload['destination_tenant_id']);
        $this->assertArrayHasKey('requested_at', $payload);
        $this->assertArrayNotHasKey('items', $payload);
    }

    public function test_accepted_event_targets_origin_tenant(): void
    {
        [$group, $spinoff] = $this->createGroupAndSpinoff();
        $transferRequest = $this->makeRequestFor($group, $spinoff);
        $transferRequest->update([
            'status' => InventoryTransferRequest::STATUS_COMPLETED,
            'responded_at' => now(),
        ]);

        $event = TransferRequestAccepted::fromModel($transferRequest);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        // Va al ORIGEN (quien creo la solicitud) porque la aceptacion
        // le interesa al que la envio.
        $this->assertSame('private-tenant.'.$group->id, $channels[0]->name);
        $this->assertSame('inventory-transfer-requests.accepted', $event->broadcastAs());
    }

    public function test_rejected_event_targets_origin_tenant(): void
    {
        [$group, $spinoff] = $this->createGroupAndSpinoff();
        $transferRequest = $this->makeRequestFor($group, $spinoff);
        $transferRequest->update([
            'status' => InventoryTransferRequest::STATUS_REJECTED,
            'response_notes' => 'No tenemos stock de esa referencia.',
            'responded_at' => now(),
        ]);

        $event = TransferRequestRejected::fromModel($transferRequest);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-tenant.'.$group->id, $channels[0]->name);
        $this->assertSame('inventory-transfer-requests.rejected', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame('No tenemos stock de esa referencia.', $payload['response_notes']);
    }

    public function test_cancelled_event_targets_destination_tenant(): void
    {
        [$group, $spinoff] = $this->createGroupAndSpinoff();
        $transferRequest = $this->makeRequestFor($group, $spinoff);
        $transferRequest->update([
            'status' => InventoryTransferRequest::STATUS_CANCELLED,
            'responded_at' => now(),
        ]);

        $event = TransferRequestCancelled::fromModel($transferRequest);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        // Va al DESTINO porque la cancelacion afecta a quien iba a
        // responder la solicitud.
        $this->assertSame('private-tenant.'.$spinoff->id, $channels[0]->name);
        $this->assertSame('inventory-transfer-requests.cancelled', $event->broadcastAs());
    }

    /**
     * @return array{0: Tenant, 1: Tenant}
     */
    private function createGroupAndSpinoff(): array
    {
        $group = Tenant::create([
            'name' => 'Grupo',
            'slug' => 'grupo-broadcast',
            'is_group' => true,
        ]);

        $spinoff = Tenant::create([
            'name' => 'Hermana',
            'slug' => 'hermana-broadcast',
            'parent_id' => $group->id,
            'is_group' => false,
        ]);

        return [$group, $spinoff];
    }

    private function createAdmin(Tenant $tenant, array $permissions): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin-broadcast-'.uniqid().'@test.test',
            'password' => bcrypt('secret123'),
        ]);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        setPermissionsTeamId($tenant->id);
        $role = Role::create([
            'name' => 'Administrador',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($tenant->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function createWarehouse(Tenant $tenant)
    {
        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = \App\Modules\Branches\Models\Branch::create([
            'name' => 'Branch '.$tenant->slug,
            'code' => 'BR-'.$tenant->slug,
        ]);

        return \App\Modules\Warehouses\Models\Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Warehouse '.$tenant->slug,
            'code' => 'WH-'.$tenant->slug,
        ]);
    }

    private function createProduct(Tenant $tenant)
    {
        return \App\Modules\Products\Models\Product::create([
            'name' => 'Product '.$tenant->slug,
            'sku' => 'SKU-'.$tenant->slug,
            'tracking_type' => \App\Modules\Products\Models\Product::TRACKING_QUANTITY,
            'unit_of_measure' => \App\Modules\Products\Models\Product::UNIT_UNIT,
            'base_price' => 100,
            'sale_currency' => \App\Modules\Products\Models\Product::CURRENCY_USD,
        ]);
    }

    private function makeRequestFor(Tenant $group, Tenant $spinoff)
    {
        app(\App\Support\Tenancy\TenantManager::class)->set($group);
        $warehouse = $this->createWarehouse($group);
        $user = User::create([
            'name' => 'Test User',
            'email' => 'transfer-broadcast-'.uniqid().'@test.test',
            'password' => bcrypt('secret123'),
        ]);

        return InventoryTransferRequest::create([
            'sequence' => 1,
            'document_number' => 'ITR-TEST-'.uniqid(),
            'origin_tenant_id' => $group->id,
            'destination_tenant_id' => $spinoff->id,
            'from_warehouse_id' => $warehouse->id,
            'destination_warehouse_id' => $warehouse->id,
            'status' => InventoryTransferRequest::STATUS_REQUESTED,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);
    }
}
