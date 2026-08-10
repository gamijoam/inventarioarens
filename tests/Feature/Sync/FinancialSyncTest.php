<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\AccessControl\Services\AccessControlService;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Products\Models\Product;
use App\Modules\Purchases\Models\PurchaseItem;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Verifica que las cuentas por pagar (CxP) emiten eventos de sync de forma
 * automatica (via trait Syncable) y que la nube las aplica (SyncEventApplier),
 * garantizando sincronizacion bidireccional local<->nube.
 */
class FinancialSyncTest extends TestCase
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
        $supplier = Supplier::create([
            'name' => 'Proveedor Test',
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => 'J-11111111-1',
        ]);

        return [$tenant, $user, $warehouse, $product, $supplier];
    }

    private function createPayable(Tenant $tenant, Supplier $supplier, PurchaseOrder $po): AccountsPayable
    {
        return app(AccountsPayableService::class)->createForPurchase($po);
    }

    private function createReceivedPurchase(Tenant $tenant, User $user, Warehouse $warehouse, Product $product, Supplier $supplier): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'document_number' => 'COMPRA-SYNC-001',
            'issued_at' => now(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'total_base_amount' => 100.0,
            'total_local_amount' => 100.0,
            'received_base_amount' => 100.0,
            'received_local_amount' => 100.0,
            'created_by' => $user->id,
        ]);
        PurchaseItem::create([
            'tenant_id' => $tenant->id,
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_cost' => 10,
            'total_cost' => 100,
            'base_unit_cost' => 10,
            'base_total_cost' => 100,
            'received_quantity' => 10,
        ]);

        return $po;
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

    public function test_payable_creation_emits_sync_event_via_trait(): void
    {
        [$tenant, $user, $warehouse, $product, $supplier] = $this->setupTenant();
        $po = $this->createReceivedPurchase($tenant, $user, $warehouse, $product, $supplier);

        $this->createPayable($tenant, $supplier, $po);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'accounts_payable.created',
        ]);
    }

    public function test_payable_payment_emits_sync_event_via_trait(): void
    {
        [$tenant, $user, $warehouse, $product, $supplier] = $this->setupTenant();
        $po = $this->createReceivedPurchase($tenant, $user, $warehouse, $product, $supplier);
        $payable = $this->createPayable($tenant, $supplier, $po);

        app(AccountsPayableService::class)->registerPayment($payable, $user, [
            'payment_currency' => 'USD',
            'amount' => 40,
            'method' => 'transferencia',
            'reference' => 'PAGO-SYNC-1',
        ]);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'accounts_payable.payment_registered',
        ]);
    }

    public function test_applier_applies_payable_created_to_cloud(): void
    {
        [$tenant, $user, $warehouse, $product, $supplier] = $this->setupTenant();
        $po = $this->createReceivedPurchase($tenant, $user, $warehouse, $product, $supplier);

        $this->enqueueEvent($tenant->id, 'accounts_payable.created', [
            'document_number' => 'CXP-SYNC-001',
            'purchase_order_id' => 999, // id local, NO existe en la nube
            'purchase_order_document' => $po->document_number,
            'supplier_document' => 'J-11111111-1',
            'supplier_name' => 'Proveedor Test',
            'status' => 'pending',
            'currency' => 'USD',
            'original_base_amount' => '100.0000',
            'original_local_amount' => '100.0000',
            'balance_base_amount' => '100.0000',
            'balance_local_amount' => '100.0000',
        ], 200);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('accounts_payables', [
            'tenant_id' => $tenant->id,
            'document_number' => 'CXP-SYNC-001',
            'status' => 'pending',
            'original_base_amount' => 100.0,
            'balance_base_amount' => 100.0,
        ]);
    }

    public function test_applier_applies_receivable_created_to_cloud(): void
    {
        [$tenant] = $this->setupTenant();
        $sale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => 50,
            'total_local_amount' => 50,
        ]);

        $this->enqueueEvent($tenant->id, 'accounts_receivable.created', [
            'document_number' => 'VENTA-SYNC-001',
            'sale_id' => $sale->id,
            'status' => 'pending',
            'currency' => 'USD',
            'original_base_amount' => '50.0000',
            'original_local_amount' => '50.0000',
            'balance_base_amount' => '50.0000',
            'balance_local_amount' => '50.0000',
        ], 300);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('accounts_receivables', [
            'tenant_id' => $tenant->id,
            'document_number' => 'VENTA-SYNC-001',
            'status' => 'pending',
            'original_base_amount' => 50.0,
            'balance_base_amount' => 50.0,
        ]);
    }

    public function test_confirming_plain_sale_emits_sale_confirmed_event(): void
    {
        [$tenant, $user, $warehouse, $product] = $this->setupTenant();
        StockBalance::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);

        $sale = app(SaleService::class)->createDraft($user, [
            [
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 2,
            ],
        ]);
        app(SaleService::class)->confirm($sale, $user);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'sale.confirmed',
        ]);
    }

    public function test_confirming_pos_sale_does_not_emit_sale_confirmed(): void
    {
        [$tenant, $user, $warehouse, $product] = $this->setupTenant();
        StockBalance::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);

        // Simula una venta con PosOrder asociado (flujo POS).
        $sale = $this->confirmedSaleWithPosOrder($tenant, $user, $warehouse, $product);
        $this->assertNotNull($sale);

        $this->assertDatabaseMissing('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'sale.confirmed',
        ]);
    }

    public function test_applier_applies_sale_confirmed_to_cloud(): void
    {
        [$tenant, , $warehouse, $product] = $this->setupTenant();
        $customer = Customer::create([
            'name' => 'Cliente Sync',
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => 'V-22222222',
        ]);

        $this->enqueueEvent($tenant->id, 'sale.confirmed', [
            'sale_id' => 77,
            'status' => 'confirmed',
            'customer_document_type' => 'V',
            'customer_document_number' => 'V-22222222',
            'total_base_amount' => '200.0000',
            'total_local_amount' => '200.0000',
            'confirmed_at' => now()->toISOString(),
            'items' => [[
                'sku' => $product->sku,
                'warehouse_code' => $warehouse->code,
                'quantity' => '2.0000',
                'unit_price' => '100.0000',
                'base_unit_price' => '100.0000',
                'base_total_amount' => '200.0000',
                'total_amount' => '200.0000',
                'sale_currency' => 'USD',
            ]],
        ], 77);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('sales', [
            'tenant_id' => $tenant->id,
            'sync_source_id' => 77,
            'status' => 'confirmed',
            'customer_id' => $customer->id,
            'total_base_amount' => 200.0,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'quantity' => 2.0,
        ]);
    }

    private function confirmedSaleWithPosOrder(Tenant $tenant, User $user, Warehouse $warehouse, Product $product): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'status' => Sale::STATUS_CONFIRMED,
            'customer_id' => null,
            'total_base_amount' => 20,
            'total_local_amount' => 20,
            'created_by' => $user->id,
        ]);
        PosOrder::create([
            'tenant_id' => $tenant->id,
            'sale_id' => $sale->id,
            'status' => 'paid',
            'total_base_amount' => 20,
            'total_local_amount' => 20,
            'paid_base_amount' => 20,
            'paid_local_amount' => 20,
        ]);

        return $sale->refresh();
    }

    public function test_assigning_role_emits_user_roles_synced(): void
    {
        [$tenant, $user] = $this->setupTenant();
        $target = User::factory()->create(['name' => 'Cajero', 'email' => 'cajero.sync@test.test']);
        $target->tenants()->attach($tenant, ['status' => 'active']);
        Permission::findOrCreate('sales.create', 'web');

        app(AccessControlService::class)->updateUserRoles(
            $target,
            ['Vendedor'],
            $user,
            $tenant,
        );

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'user.roles.synced',
        ]);
    }

    public function test_applier_applies_user_roles_synced_to_destination(): void
    {
        [$tenant] = $this->setupTenant();

        $this->enqueueEvent($tenant->id, 'user.roles.synced', [
            'email' => 'nuevo.cajero@test.test',
            'name' => 'Nuevo Cajero',
            'password_hash' => Hash::make('Secret1234!'),
            'is_platform_admin' => false,
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'is_active' => true,
            'roles' => ['Vendedor'],
        ], 1);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('users', [
            'email' => 'nuevo.cajero@test.test',
            'name' => 'Nuevo Cajero',
        ]);
        $user = User::where('email', 'nuevo.cajero@test.test')->firstOrFail();
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $this->assertTrue($user->hasRole('Vendedor'));
    }

    public function test_applier_inactivates_user_when_is_active_false(): void
    {
        [$tenant] = $this->setupTenant();
        $user = User::factory()->create(['name' => 'Cajero', 'email' => 'cajero.inactivo@test.test']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this->enqueueEvent($tenant->id, 'user.roles.synced', [
            'email' => 'cajero.inactivo@test.test',
            'name' => 'Cajero',
            'is_platform_admin' => false,
            'is_active' => false,
            'roles' => [],
        ], 2);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'inactive',
        ]);
    }
}
