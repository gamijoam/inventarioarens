<?php

namespace Tests\Feature\Purchases;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Products\Models\Product;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica la integracion entre PurchaseOrderService::receive() y
 * InventoryValuationService::recalculate(). Sin este cableado, el WAC
 * (products.average_cost) queda stale despues de cada compra recibida.
 */
class PurchaseWacRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $branch = Branch::create(['name' => 'B', 'code' => 'B1']);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W1',
            'code' => 'W1',
        ]);
        $user = User::create([
            'name' => 'U',
            'email' => 'u@t.test',
            'password' => 'secret',
        ]);

        return [$tenant, $branch, $warehouse, $user];
    }

    private function product(int $tenantId, string $sku = 'P-1', float $initialWac = 0.0): Product
    {
        return Product::create([
            'tenant_id' => $tenantId,
            'name' => 'P',
            'sku' => $sku,
            'tracking_type' => 'quantity',
            'average_cost' => $initialWac,
        ]);
    }

    public function test_wac_is_recalculated_after_receiving_purchase(): void
    {
        [$tenant, , $warehouse, $user] = $this->setupTenant();
        $product = $this->product($tenant->id, 'TEST-1');

        // Crear borrador de compra: 10 unidades a $5.00 c/u.
        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => null,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'document_number' => 'PO-TEST-001',
            'issued_at' => now()->toDateString(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'created_by' => $user->id,
        ]);
        $po->items()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_cost' => 5.00,
            'total_cost' => 50.00,
            'base_unit_cost' => 5.00,
            'base_total_cost' => 50.00,
        ]);

        // WAC inicial es 0.
        $this->assertEquals(0.0, (float) $product->fresh()->average_cost);

        // Recibir la compra via el service.
        $service = app(PurchaseOrderService::class);
        $service->receive($po->fresh(), $user);

        // El WAC debe haberse actualizado a 5.00 (unica entrada de 10 unidades a $5).
        $this->assertEquals(5.0, (float) $product->fresh()->average_cost);
    }

    public function test_wac_blends_old_and_new_when_receiving_partial_purchase(): void
    {
        [$tenant, , $warehouse, $user] = $this->setupTenant();
        $product = $this->product($tenant->id, 'TEST-2');

        // Compra 1: 4 unidades a $10.00 = WAC $10.00.
        $po1 = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'document_number' => 'PO-A',
            'issued_at' => now()->toDateString(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'created_by' => $user->id,
        ]);
        $po1->items()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 4,
            'unit_cost' => 10.00,
            'total_cost' => 40.00,
            'base_unit_cost' => 10.00,
            'base_total_cost' => 40.00,
        ]);
        app(PurchaseOrderService::class)->receive($po1->fresh(), $user);
        $this->assertEquals(10.0, (float) $product->fresh()->average_cost);

        // Compra 2: 6 unidades a $20.00 -> nuevo WAC = (4*10 + 6*20) / 10 = $16.00.
        $po2 = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'document_number' => 'PO-B',
            'issued_at' => now()->toDateString(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'created_by' => $user->id,
        ]);
        $po2->items()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 6,
            'unit_cost' => 20.00,
            'total_cost' => 120.00,
            'base_unit_cost' => 20.00,
            'base_total_cost' => 120.00,
        ]);
        app(PurchaseOrderService::class)->receive($po2->fresh(), $user);

        $this->assertEquals(16.0, (float) $product->fresh()->average_cost);
    }
}
