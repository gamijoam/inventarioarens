<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sync\Services\SyncEventApplier;
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

class InventoryTransferVariantSyncTest extends TestCase
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

    public function test_transfer_variant_event_syncs_items_and_variant_stock_to_other_node(): void
    {
        [$tenantA, $tenantB, $warehouseAF, $warehouseAT, $warehouseBF, $warehouseBT, $productA, $productB, $variantA, $variantB, $userA] = $this->setupEnv();

        $this->stockVariant($tenantA, $warehouseAF, $productA, $variantA, $userA, 5);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $warehouseAF->id,
                'to_warehouse_id' => $warehouseAT->id,
                'reason' => 'Sync con variantes',
                'items' => [
                    ['product_id' => $productA->id, 'product_variant_id' => $variantA->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated();

        $transfer = DB::table('inventory_transfers')->where('tenant_id', $tenantA->id)->latest('id')->first();

        $outboxEvents = DB::table('sync_outbox')
            ->where('tenant_id', $tenantA->id)
            ->whereIn('event_type', ['inventory_transfer.created', 'stock_movement.created'])
            ->get();

        $this->assertTrue(
            $outboxEvents->contains(fn ($event): bool => $event->event_type === 'inventory_transfer.created'),
            'Falta el evento inventory_transfer.created en el outbox'
        );

        $transferPayload = $outboxEvents->firstWhere('event_type', 'inventory_transfer.created');
        $decodedTransferPayload = json_decode($transferPayload->payload, true);
        $this->assertSame($variantA->id, $decodedTransferPayload['items'][0]['product_variant_id'], 'El item del traslado no lleva product_variant_id en el payload');
        $this->assertSame('Azul', $decodedTransferPayload['items'][0]['product_variant_color'], 'El item del traslado no lleva product_variant_color en el payload');

        $movementPayloads = $outboxEvents->where('event_type', 'stock_movement.created')
            ->map(fn ($event): array => json_decode($event->payload, true))
            ->all();
        $this->assertNotEmpty($movementPayloads);
        foreach ($movementPayloads as $payload) {
            $this->assertSame('Azul', $payload['product_variant_color'], 'El movimiento de stock no lleva product_variant_color en el payload');
        }

        foreach ($outboxEvents as $index => $event) {
            DB::table('sync_inbox')->insert([
                'tenant_id' => $tenantB->id,
                'event_uuid' => Str::uuid()->toString(),
                'event_type' => $event->event_type,
                'aggregate_type' => $event->aggregate_type,
                'aggregate_id' => $event->aggregate_id,
                'payload_hash' => null,
                'payload' => $event->payload,
                'status' => 'received',
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $applier = app(SyncEventApplier::class);
        foreach (DB::table('sync_inbox')->where('tenant_id', $tenantB->id)->orderBy('id')->get() as $inboxRow) {
            $applier->applyOne($tenantB, (array) $inboxRow);
        }

        $replicatedItem = DB::table('inventory_transfer_items')
            ->where('tenant_id', $tenantB->id)
            ->where('product_id', $productB->id)
            ->first();

        $this->assertNotNull($replicatedItem, 'No se replico el item del traslado en el nodo destino');
        $this->assertSame($variantB->id, (int) $replicatedItem->product_variant_id, 'El item replicado no resolvio la variante local');

        $fromBalanceB = StockBalance::query()
            ->where('warehouse_id', $warehouseBF->id)
            ->where('product_id', $productB->id)
            ->where('product_variant_id', $variantB->id)
            ->firstOrFail();
        $toBalanceB = StockBalance::query()
            ->where('warehouse_id', $warehouseBT->id)
            ->where('product_id', $productB->id)
            ->where('product_variant_id', $variantB->id)
            ->firstOrFail();

        $this->assertSame(3.0, (float) $fromBalanceB->quantity_available, 'El nodo destino no restó la variante en el almacén de origen');
        $this->assertSame(2.0, (float) $toBalanceB->quantity_available, 'El nodo destino no sumó la variante en el almacén de destino');
    }

    private function setupEnv(): array
    {
        $tenantA = Tenant::create(['name' => 'Nube A', 'slug' => 'nube-a']);
        $tenantB = Tenant::create(['name' => 'Local B', 'slug' => 'local-b']);

        $userA = User::factory()->create();
        $userA->tenants()->attach($tenantA, ['status' => 'active']);

        app(TenantManager::class)->set($tenantA);
        setPermissionsTeamId($tenantA->id);
        $role = Role::firstOrCreate(['name' => 'Sync Variante A', 'guard_name' => 'web']);
        $role->syncPermissions(['inventory_transfers.create', 'inventory_transfers.view']);
        $userA->assignRole($role);

        $branchA = Branch::create(['name' => 'Sucursal A', 'code' => 'BR-SYNC-A']);
        $warehouseAF = Warehouse::create(['branch_id' => $branchA->id, 'name' => 'Origen', 'code' => 'FROM-SYNCV']);
        $warehouseAT = Warehouse::create(['branch_id' => $branchA->id, 'name' => 'Destino', 'code' => 'TO-SYNCV']);
        $productA = Product::create(['name' => 'Telefono', 'sku' => 'SKU-SYNCV', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 100, 'sale_currency' => Product::CURRENCY_USD]);
        $variantA = ProductVariant::create(['product_id' => $productA->id, 'color' => 'Azul', 'position' => 0]);

        app(TenantManager::class)->set($tenantB);
        setPermissionsTeamId($tenantB->id);
        $branchB = Branch::create(['name' => 'Sucursal B', 'code' => 'BR-SYNC-B']);
        $warehouseBF = Warehouse::create(['branch_id' => $branchB->id, 'name' => 'Origen B', 'code' => 'FROM-SYNCV']);
        $warehouseBT = Warehouse::create(['branch_id' => $branchB->id, 'name' => 'Destino B', 'code' => 'TO-SYNCV']);
        $productB = Product::create(['name' => 'Telefono', 'sku' => 'SKU-SYNCV', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 100, 'sale_currency' => Product::CURRENCY_USD]);
        $variantB = ProductVariant::create(['product_id' => $productB->id, 'color' => 'Azul', 'position' => 0]);

        DB::table('stock_balances')->insert([
            [
                'tenant_id' => $tenantB->id,
                'warehouse_id' => $warehouseBF->id,
                'product_id' => $productB->id,
                'product_variant_id' => $variantB->id,
                'quantity_available' => 5,
                'quantity_reserved' => 0,
                'quantity_damaged' => 0,
            ],
        ]);

        return [$tenantA, $tenantB, $warehouseAF, $warehouseAT, $warehouseBF, $warehouseBT, $productA, $productB, $variantA, $variantB, $userA];
    }

    private function stockVariant(Tenant $tenant, Warehouse $warehouse, Product $product, ProductVariant $variant, User $user, float $quantity): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        app(InventoryMovementService::class)->purchase(
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: 50,
            createdBy: $user,
            productVariantId: $variant->id,
            reason: 'Stock sync variante',
        );
    }
}
