<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica que los eventos `purchase_order.created` y `purchase_order.received`
 * emitidos por el modulo de Compras (FASE 0 de la sesion de modulo de
 * compras) se aplican correctamente en la nube via SyncEventApplier.
 *
 * El sync de Purchases es importante para mantener el stock consolidado
 * entre el nodo local (donde se registra la compra formal) y la nube
 * (que ve la entrada de stock equivalente).
 */
class PurchaseOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $branch = Branch::create(['name' => 'B', 'code' => 'B1']);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W1',
            'code' => 'WH-01',
        ]);
        $product = Product::create([
            'name' => 'P',
            'sku' => 'TEST-SKU-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10.0,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$tenant, $user, $branch, $warehouse, $product];
    }

    private function enqueueEvent(int $tenantId, string $eventType, array $payload, int $aggregateId = 1): void
    {
        $now = now();
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenantId,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => str_replace('.created', '', $eventType),
            'aggregate_id' => $aggregateId,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_purchase_order_created_event_persists_metadata_in_cloud(): void
    {
        [$tenant] = $this->setupTenant();
        $poId = 99;

        $payload = [
            'document_number' => 'PO-2026-0001',
            'status' => 'draft',
            'supplier_name' => 'Distribuidora XYZ',
            'issued_at' => '2026-07-14',
            'due_date' => '2026-07-21',
            'purchase_currency' => 'USD',
            'exchange_rate_type_id' => null,
            'exchange_rate' => null,
            'total_base_amount' => '500.0000',
            'total_local_amount' => '500.0000',
            'items' => [
                [
                    'sku' => 'TEST-SKU-001',
                    'warehouse_code' => 'WH-01',
                    'quantity' => '50.0000',
                    'unit_cost' => '10.0000',
                    'base_unit_cost' => '10.0000',
                ],
            ],
        ];

        $this->enqueueEvent($tenant->id, 'purchase_order.created', $payload, $poId);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('purchase_orders', [
            'tenant_id' => $tenant->id,
            'document_number' => 'PO-2026-0001',
            'status' => 'draft',
            'supplier_id' => null, // suppliers no se replican en esta iteracion
            'purchase_currency' => 'USD',
            'total_base_amount' => 500.0,
        ]);
    }

    public function test_purchase_order_received_event_creates_product_entry_in_cloud(): void
    {
        [$tenant, , , $warehouse, $product] = $this->setupTenant();
        $poId = 99;

        $payload = [
            'document_number' => 'PO-2026-0001',
            'status' => 'received',
            'supplier_name' => 'Distribuidora XYZ',
            'purchase_currency' => 'USD',
            'received_at' => now()->toISOString(),
            'items' => [
                [
                    'sku' => $product->sku,
                    'warehouse_code' => $warehouse->code,
                    'quantity' => '10.0000',
                    'unit_cost' => '8.5000',
                    'serial_units' => [],
                ],
            ],
        ];

        $this->enqueueEvent($tenant->id, 'purchase_order.received', $payload, $poId);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        // Verifica que se creo un product_entry con el document_number del PO
        // y metadata del proveedor en notes.
        $this->assertDatabaseHas('product_entries', [
            'tenant_id' => $tenant->id,
            'document_number' => 'PO-2026-0001',
            'status' => 'processed',
        ]);
        $entry = DB::table('product_entries')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', 'PO-2026-0001')
            ->first();
        $this->assertStringContainsString('Distribuidora XYZ', (string) $entry->notes);
        $this->assertStringContainsString('PO-2026-0001', (string) $entry->notes);

        // Verifica que se creo el item con la cantidad y el costo USD.
        $this->assertDatabaseHas('product_entry_items', [
            'tenant_id' => $tenant->id,
            'product_entry_id' => $entry->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '10.0000',
            'unit_cost' => '8.5000',
        ]);

        // Verifica que se incremento el stock_balance.
        $this->assertSame('10.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity_available'));

        // Verifica que se creo el stock_movement.
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'entry',
            'reference_type' => 'product_entry',
            'reference_id' => $entry->id,
            'quantity' => '10.0000',
            'unit_cost' => '8.5000',
        ]);
    }

    public function test_purchase_order_received_event_creates_available_serial_units(): void
    {
        [$tenant, , , $warehouse, $product] = $this->setupTenant();
        DB::table('products')->where('id', $product->id)->update([
            'tracking_type' => Product::TRACKING_SERIALIZED,
        ]);

        $payload = [
            'document_number' => 'PO-2026-SERIALS',
            'status' => 'received',
            'supplier_name' => 'Distribuidora IMEI',
            'purchase_currency' => 'USD',
            'received_at' => now()->toISOString(),
            'items' => [
                [
                    'sku' => $product->sku,
                    'warehouse_code' => $warehouse->code,
                    'quantity' => '2.0000',
                    'unit_cost' => '400.0000',
                    'serial_units' => [
                        ['serial_type' => 'imei', 'serial_number' => '111111111111111'],
                        ['serial_type' => 'imei', 'serial_number' => '222222222222222'],
                    ],
                ],
            ],
        ];

        $this->enqueueEvent($tenant->id, 'purchase_order.received', $payload, 100);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => 'imei',
            'serial_number' => '111111111111111',
            'status' => 'available',
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => 'imei',
            'serial_number' => '222222222222222',
            'status' => 'available',
        ]);
        $this->assertSame(2, DB::table('product_units')
            ->where('tenant_id', $tenant->id)
            ->where('product_id', $product->id)
            ->where('status', 'available')
            ->count());
    }

    public function test_purchase_order_received_is_idempotent(): void
    {
        [$tenant, , , $warehouse, $product] = $this->setupTenant();
        $poId = 99;

        $payload = [
            'document_number' => 'PO-2026-IDEMPOTENT',
            'status' => 'received',
            'supplier_name' => 'Proveedor Test',
            'purchase_currency' => 'USD',
            'received_at' => now()->toISOString(),
            'items' => [
                [
                    'sku' => $product->sku,
                    'warehouse_code' => $warehouse->code,
                    'quantity' => '5.0000',
                    'unit_cost' => '12.0000',
                    'serial_units' => [],
                ],
            ],
        ];

        // Primer intento: aplica y crea stock.
        $this->enqueueEvent($tenant->id, 'purchase_order.received', $payload, $poId);
        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        // Re-procesar el mismo evento NO debe duplicar stock.
        $this->enqueueEvent($tenant->id, 'purchase_order.received', $payload, $poId);
        $summary2 = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary2['applied']);

        $this->assertSame('5.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity_available'));
    }
}
