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

    private function product(int $tenantId, string $sku = 'P-1', float $initialWac = 0.0, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'tenant_id' => $tenantId,
            'name' => 'P',
            'sku' => $sku,
            'tracking_type' => 'quantity',
            'average_cost' => $initialWac,
        ], $attributes));
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

        // Compra 2: 6 unidades a $20.00 -> costo de reposicion directo = $20.00 (sin dilucion WAC).
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

        $this->assertEquals(20.0, (float) $product->fresh()->average_cost);
        $this->assertEquals(20.0, (float) $product->fresh()->last_purchase_cost);
    }

    public function test_receive_updates_cost_reference_but_never_changes_manual_sale_price(): void
    {
        [$tenant, , $warehouse, $user] = $this->setupTenant();
        $product = $this->product($tenant->id, 'IPHONE-15', 0.0, [
            'name' => 'IPHONE 15',
            'base_price' => 400.00,
            'profit_margin' => 25.00,
            'last_purchase_cost' => 320.00,
            'pricing_mode' => Product::PRICING_MANUAL,
        ]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'document_number' => 'PO-IPHONE-15',
            'issued_at' => now()->toDateString(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'created_by' => $user->id,
        ]);
        $po->items()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => 500.00,
            'total_cost' => 500.00,
            'base_unit_cost' => 500.00,
            'base_total_cost' => 500.00,
        ]);

        $received = app(PurchaseOrderService::class)->receive($po->fresh(), $user);
        $this->assertEquals(500.0, (float) $product->fresh()->last_purchase_cost);
        $this->assertEquals(400.00, (float) $product->fresh()->base_price);
    }

    public function test_receive_recalculates_sale_price_for_automatic_products(): void
    {
        [$tenant, , $warehouse, $user] = $this->setupTenant();
        $product = $this->product($tenant->id, 'AUTO-PRICE', 0.0, [
            'base_price' => 100.00,
            'profit_margin' => 25.00,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
        ]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'document_number' => 'PO-AUTO-PRICE',
            'issued_at' => now()->toDateString(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'created_by' => $user->id,
        ]);
        $po->items()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => 100.00,
            'total_cost' => 100.00,
            'base_unit_cost' => 100.00,
            'base_total_cost' => 100.00,
        ]);

        app(PurchaseOrderService::class)->receive($po->fresh(), $user);

        $this->assertEquals(100.0, (float) $product->fresh()->last_purchase_cost);
        $this->assertEquals(125.00, (float) $product->fresh()->base_price);
    }

    public function test_receive_updates_sale_price_when_explicit_new_sale_price_is_provided(): void
    {
        [$tenant, , $warehouse, $user] = $this->setupTenant();
        $product = $this->product($tenant->id, 'MANUAL-WITH-NEW-PRICE', 0.0, [
            'base_price' => 10.00,
            'pricing_mode' => Product::PRICING_MANUAL,
        ]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'document_number' => 'PO-MANUAL-NEW-PRICE',
            'issued_at' => now()->toDateString(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'created_by' => $user->id,
        ]);
        $po->items()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_cost' => 8.00,
            'total_cost' => 16.00,
            'base_unit_cost' => 8.00,
            'base_total_cost' => 16.00,
            'new_sale_price' => 15.00,
        ]);

        app(PurchaseOrderService::class)->receive($po->fresh(), $user);

        $this->assertEquals(8.0, (float) $product->fresh()->last_purchase_cost);
        $this->assertEquals(15.00, (float) $product->fresh()->base_price);
    }

    public function test_receive_auto_updates_price_when_cost_rises_or_drops_preserving_margin(): void
    {
        [$tenant, , $warehouse, $user] = $this->setupTenant();

        // Producto con costo 10 y PVP 15 (margen implicito 50%), sin profit_margin seteado
        $product = $this->product($tenant->id, 'DINAMICO', 0.0, [
            'name' => 'PRODUCTO DINAMICO',
            'base_price' => 15.00,
            'last_purchase_cost' => 10.00,
            'profit_margin' => null,
            'pricing_mode' => Product::PRICING_AUTOMATIC,
        ]);

        // 1. Nueva compra donde el costo SUBE a $20.00
        $poRise = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'document_number' => 'PO-RISE',
            'issued_at' => now()->toDateString(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'created_by' => $user->id,
        ]);
        $poRise->items()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => 20.00,
            'total_cost' => 20.00,
            'base_unit_cost' => 20.00,
            'base_total_cost' => 20.00,
        ]);
        app(PurchaseOrderService::class)->receive($poRise->fresh(), $user);

        $fresh = $product->fresh();
        // Costo sube a $20
        $this->assertEquals(20.00, (float) $fresh->last_purchase_cost);
        $this->assertEquals(20.00, (float) $fresh->average_cost);
        // Margen se calculo y guardo en 50%
        $this->assertEquals(50.00, (float) $fresh->profit_margin);
        // PVP subio automaticamente a $30 ($20 * 1.50)
        $this->assertEquals(30.00, (float) $fresh->base_price);

        // 2. Nueva compra donde el costo BAJA a $8.00
        $poDrop = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'document_number' => 'PO-DROP',
            'issued_at' => now()->toDateString(),
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'created_by' => $user->id,
        ]);
        $poDrop->items()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => 8.00,
            'total_cost' => 8.00,
            'base_unit_cost' => 8.00,
            'base_total_cost' => 8.00,
        ]);
        app(PurchaseOrderService::class)->receive($poDrop->fresh(), $user);

        $freshDrop = $product->fresh();
        // Costo baja a $8
        $this->assertEquals(8.00, (float) $freshDrop->last_purchase_cost);
        $this->assertEquals(8.00, (float) $freshDrop->average_cost);
        // PVP bajo automaticamente a $12 ($8 * 1.50)
        $this->assertEquals(12.00, (float) $freshDrop->base_price);
    }
}
