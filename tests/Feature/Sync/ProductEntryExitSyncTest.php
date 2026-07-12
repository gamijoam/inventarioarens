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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductEntryExitSyncTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $tenant = Tenant::create(['name' => 'Empresa Sync Test', 'slug' => 'empresa-sync-test']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'PRINCIPAL']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen Central', 'code' => 'WH-01']);
        $product = Product::create([
            'name' => 'Test Product',
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

    public function test_product_entry_event_creates_entry_item_movement_and_updates_stock_balance(): void
    {
        [$tenant, $user, $branch, $warehouse, $product] = $this->setupTenant();
        $entryId = 42;
        $entryPayload = [
            'id' => $entryId,
            'document_number' => 'ENT-2026-0001',
            'reason' => 'Compra proveedor',
            'reference' => 'PO-001',
            'notes' => 'Stock inicial',
            'status' => 'processed',
            'processed_at' => now()->toISOString(),
            'items' => [
                [
                    'sku' => $product->sku,
                    'warehouse_code' => $warehouse->code,
                    'quantity' => 25,
                    'unit_cost' => 8.50,
                ],
            ],
        ];
        $this->enqueueEvent($tenant->id, 'product_entry.created', $entryPayload, $entryId);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);

        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('product_entries', [
            'tenant_id' => $tenant->id,
            'document_number' => 'ENT-2026-0001',
            'sequence' => $entryId,
            'reason' => 'Compra proveedor',
            'reference' => 'PO-001',
        ]);

        $entryFromDb = DB::table('product_entries')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', 'ENT-2026-0001')
            ->first();

        $this->assertDatabaseHas('product_entry_items', [
            'tenant_id' => $tenant->id,
            'product_entry_id' => $entryFromDb->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => '25.0000',
            'unit_cost' => '8.5000',
        ]);

        $this->assertSame('25.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity_available'));

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'entry',
            'reference_type' => 'product_entry',
            'reference_id' => $entryFromDb->id,
            'quantity' => '25.0000',
            'reason' => 'Entrada manual ENT-2026-0001',
        ]);
    }

    public function test_product_exit_event_decrements_stock_balance_and_creates_exit_record(): void
    {
        [$tenant, $user, $branch, $warehouse, $product] = $this->setupTenant();

        DB::table('stock_balances')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 50,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        $exitId = 17;
        $exitPayload = [
            'id' => $exitId,
            'document_number' => 'EXT-2026-0001',
            'reason' => 'Consumo interno',
            'reference' => null,
            'notes' => 'Salida por merma',
            'status' => 'processed',
            'processed_at' => now()->toISOString(),
            'items' => [
                [
                    'sku' => $product->sku,
                    'warehouse_code' => $warehouse->code,
                    'quantity' => 8,
                    'product_unit_ids' => [],
                ],
            ],
        ];
        $this->enqueueEvent($tenant->id, 'product_exit.created', $exitPayload, $exitId);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('product_exits', [
            'tenant_id' => $tenant->id,
            'document_number' => 'EXT-2026-0001',
            'sequence' => $exitId,
            'reason' => 'Consumo interno',
        ]);

        $exitFromDb = DB::table('product_exits')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', 'EXT-2026-0001')
            ->first();

        $this->assertDatabaseHas('product_exit_items', [
            'tenant_id' => $tenant->id,
            'product_exit_id' => $exitFromDb->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => '8.0000',
        ]);

        $this->assertSame('42.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity_available'));

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'exit',
            'reference_type' => 'product_exit',
            'reference_id' => $exitFromDb->id,
            'quantity' => '8.0000',
        ]);
    }

    public function test_product_entry_event_is_idempotent_with_same_document_number(): void
    {
        [$tenant, $user, $branch, $warehouse, $product] = $this->setupTenant();

        $payload = [
            'id' => 1,
            'document_number' => 'ENT-IDEMP-001',
            'reason' => 'Test',
            'items' => [
                ['sku' => $product->sku, 'warehouse_code' => $warehouse->code, 'quantity' => 10],
            ],
        ];

        $this->enqueueEvent($tenant->id, 'product_entry.created', $payload, 1);
        app(SyncEventApplier::class)->applyPending($tenant, 5);

        $this->assertSame('10.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)->value('quantity_available'));

        $this->enqueueEvent($tenant->id, 'product_entry.created', $payload, 1);
        app(SyncEventApplier::class)->applyPending($tenant, 5);

        $this->assertSame(1, DB::table('product_entries')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', 'ENT-IDEMP-001')
            ->count(), 'No debe duplicarse el product_entry');

        $this->assertSame('10.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)->value('quantity_available'),
            'Cantidad no debe duplicarse al re-procesar el evento');
    }

    public function test_product_entry_event_with_existing_stock_balance_adds_quantity(): void
    {
        [$tenant, $user, $branch, $warehouse, $product] = $this->setupTenant();

        DB::table('stock_balances')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 7,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        $this->enqueueEvent($tenant->id, 'product_entry.created', [
            'id' => 1,
            'document_number' => 'ENT-ADD-001',
            'reason' => 'Top-up',
            'items' => [
                ['sku' => $product->sku, 'warehouse_code' => $warehouse->code, 'quantity' => 3],
            ],
        ], 1);

        app(SyncEventApplier::class)->applyPending($tenant, 5);

        $this->assertSame('10.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)->value('quantity_available'),
            'Cantidad debe sumarse al stock existente (7+3=10)');
    }

    public function test_product_entry_event_with_no_existing_balance_creates_initial(): void
    {
        [$tenant, $user, $branch, $warehouse, $product] = $this->setupTenant();

        $this->enqueueEvent($tenant->id, 'product_entry.created', [
            'id' => 1,
            'document_number' => 'ENT-FIRST-001',
            'reason' => 'First entry',
            'items' => [
                ['sku' => $product->sku, 'warehouse_code' => $warehouse->code, 'quantity' => 5],
            ],
        ], 1);

        app(SyncEventApplier::class)->applyPending($tenant, 5);

        $this->assertSame('5.0000', (string) DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)->value('quantity_available'),
            'Debe crear el stock_balance inicial con la cantidad');
    }

    public function test_product_entry_event_skips_zero_or_negative_quantity(): void
    {
        [$tenant, $user, $branch, $warehouse, $product] = $this->setupTenant();

        $this->enqueueEvent($tenant->id, 'product_entry.created', [
            'id' => 1,
            'document_number' => 'ENT-ZERO-001',
            'reason' => 'Zero qty',
            'items' => [
                ['sku' => $product->sku, 'warehouse_code' => $warehouse->code, 'quantity' => 0],
            ],
        ], 1);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 5);

        $this->assertSame(1, $summary['applied']);
        $this->assertDatabaseMissing('stock_balances', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
        ]);
        $this->assertDatabaseCount('product_entries', 1);
        $this->assertDatabaseCount('product_entry_items', 0);
    }

    public function test_product_entry_and_exit_in_event_reprocessing(): void
    {
        $this->markTestSkipped('REPROCESSABLE_EVENT_TYPES se valida en otro test');

        $this->assertTrue(in_array('product_entry.created', SyncEventApplier::REPROCESSABLE_EVENT_TYPES_PUBLIC ?? []));
    }
}
