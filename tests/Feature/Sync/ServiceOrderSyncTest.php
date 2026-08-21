<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Workshop\Models\ServiceOrder;
use App\Modules\Workshop\Services\ServiceOrderService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sync del modulo Taller (Fase 5):
 *  - Al crear una orden de servicio se emite service_order.created en el outbox.
 *  - El applier aplica el evento (header + piezas) con timestamps.
 */
class ServiceOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_service_order_emits_sync_outbox_event(): void
    {
        [$tenant, $user, $warehouse] = $this->scaffold();

        app(ServiceOrderService::class)->create($user, [
            'type' => ServiceOrder::TYPE_REPAIR,
            'warehouse_id' => $warehouse->id,
            'customer_name' => 'Juan',
            'device_description' => 'iPhone',
        ]);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'service_order.created',
            'aggregate_type' => 'service_order',
        ]);

        $payload = json_decode((string) DB::table('sync_outbox')
            ->where('event_type', 'service_order.created')
            ->latest('id')
            ->value('payload'), true);

        $this->assertSame('SO-000001', $payload['order_number']);
        $this->assertSame($warehouse->code, $payload['warehouse_code']);
        $this->assertSame(ServiceOrder::STATUS_RECEIVED, $payload['status']);
        $this->assertNotNull($payload['created_at']);
        $this->assertNotNull($payload['updated_at']);
    }

    public function test_applier_applies_service_order_event_with_timestamps(): void
    {
        $tenant = Tenant::create(['name' => 'Sync Taller', 'slug' => 'sync-taller']);
        app(TenantManager::class)->set($tenant);

        $branch = Branch::create(['name' => 'Sucursal', 'code' => 'BR-SYNC']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Taller', 'code' => 'WH-SYNC']);
        $product = Product::create([
            'name' => 'Pieza Sync',
            'sku' => 'PIEZA-SYNC',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 30,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        $payload = json_encode([
            '_sync_aggregate_id' => 42,
            'order_number' => 'SO-009999',
            'type' => ServiceOrder::TYPE_REPAIR,
            'status' => ServiceOrder::STATUS_DELIVERED,
            'priority' => 'normal',
            'resolution' => null,
            'customer_name' => 'Cliente Sync',
            'device_description' => 'Lavadora',
            'diagnosis' => 'Cambio de motor',
            'technician_email' => null,
            'warehouse_code' => $warehouse->code,
            'labor_base_amount' => '40.0000',
            'labor_local_amount' => '0.0000',
            'parts_base_amount' => '60.0000',
            'parts_local_amount' => '0.0000',
            'total_base_amount' => '100.0000',
            'total_local_amount' => '0.0000',
            'received_at' => '2026-08-21T14:00:00+00:00',
            'diagnosed_at' => '2026-08-21T14:10:00+00:00',
            'delivered_at' => '2026-08-21T15:00:00+00:00',
            'created_at' => '2026-08-21T14:00:00+00:00',
            'updated_at' => '2026-08-21T15:00:00+00:00',
            'parts' => [
                [
                    'sku' => $product->sku,
                    'warehouse_code' => $warehouse->code,
                    'quantity' => '2.0000',
                    'unit_cost' => '25.0000',
                    'unit_price' => '30.0000',
                    'status' => ServiceOrder::PART_STATUS_CONSUMED,
                ],
            ],
        ]);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'service_order.created',
            'aggregate_type' => 'service_order',
            'payload_hash' => hash('sha256', $payload),
            'payload' => $payload,
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('service_orders', [
            'tenant_id' => $tenant->id,
            'order_number' => 'SO-009999',
            'status' => ServiceOrder::STATUS_DELIVERED,
            'total_base_amount' => '100.0000',
        ]);

        // Los timestamps no deben ser null (el frontend descarta recursos sin ellos).
        $order = DB::table('service_orders')->where('tenant_id', $tenant->id)->where('order_number', 'SO-009999')->first();
        $this->assertNotNull($order->created_at);
        $this->assertNotNull($order->updated_at);
        $this->assertNotNull($order->delivered_at);

        $this->assertDatabaseHas('service_order_parts', [
            'tenant_id' => $tenant->id,
            'service_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => '2.0000',
            'status' => ServiceOrder::PART_STATUS_CONSUMED,
        ]);
    }

    // ---- Helpers ----

    private function scaffold(): array
    {
        $tenant = Tenant::create(['name' => 'Empresa Sync', 'slug' => 'empresa-sync-taller']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'Sucursal', 'code' => 'BR-TALLER']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Taller', 'code' => 'WH-TALLER']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return [$tenant, $user, $warehouse];
    }
}
