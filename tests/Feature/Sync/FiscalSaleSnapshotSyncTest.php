<?php

namespace Tests\Feature\Sync;

use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FiscalSaleSnapshotSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_pos_sync_preserves_fiscal_totals_and_line_snapshot(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Sync Fiscal Venta', 'slug' => 'empresa-sync-fiscal-venta']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $branch = Branch::create(['name' => 'Sucursal Sync Fiscal', 'code' => 'BR-SYNC-FISCAL']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen Sync Fiscal', 'code' => 'WH-SYNC-FISCAL']);
        $rateType = ExchangeRateType::create(['code' => 'BCV', 'name' => 'BCV', 'is_default' => true]);
        $product = Product::create([
            'name' => 'Producto Sync Fiscal',
            'sku' => 'SKU-SYNC-FISCAL',
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
            'sale_exchange_rate_type_id' => $rateType->id,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);

        $payload = json_encode([
            'order_id' => 901,
            'sale_id' => 902,
            'sale_status' => 'confirmed',
            'status' => 'paid',
            'total_base_amount' => '116.0000',
            'total_local_amount' => '0.0000',
            'fiscal_taxable_base_amount' => '100.0000',
            'fiscal_taxable_local_amount' => '0.0000',
            'fiscal_tax_base_amount' => '16.0000',
            'fiscal_tax_local_amount' => '0.0000',
            'fiscal_snapshot_at' => '2026-08-29T20:00:00Z',
            'items' => [[
                'id' => 903,
                'product_sku' => $product->sku,
                'warehouse_code' => $warehouse->code,
                'quantity' => '1.0000',
                'sale_currency' => Product::CURRENCY_USD,
                'unit_price' => '100.0000',
                'total_amount' => '100.0000',
                'base_unit_price' => '100.0000',
                'base_total_amount' => '100.0000',
                'local_total_amount' => '0.0000',
                'fiscal_tax_code' => 'IVA16',
                'fiscal_tax_name' => 'IVA general',
                'fiscal_tax_category' => 'taxable',
                'fiscal_tax_rate' => '16.0000',
                'fiscal_prices_include_tax' => false,
                'fiscal_taxable_base_amount' => '100.0000',
                'fiscal_taxable_local_amount' => '0.0000',
                'fiscal_tax_base_amount' => '16.0000',
                'fiscal_tax_local_amount' => '0.0000',
                'fiscal_total_base_amount' => '116.0000',
                'fiscal_total_local_amount' => '0.0000',
                'fiscal_snapshot_at' => '2026-08-29T20:00:00Z',
                'product_unit_ids' => [],
                'product_serial_units' => [],
            ]],
            'sale' => [
                'id' => 902,
                'status' => 'confirmed',
                'total_base_amount' => '116.0000',
                'total_local_amount' => '0.0000',
                'fiscal_taxable_base_amount' => '100.0000',
                'fiscal_taxable_local_amount' => '0.0000',
                'fiscal_tax_base_amount' => '16.0000',
                'fiscal_tax_local_amount' => '0.0000',
                'fiscal_snapshot_at' => '2026-08-29T20:00:00Z',
                'confirmed_at' => '2026-08-29T20:00:00Z',
            ],
            'order' => [
                'id' => 901,
                'status' => 'paid',
                'total_base_amount' => '116.0000',
                'total_local_amount' => '0.0000',
                'fiscal_taxable_base_amount' => '100.0000',
                'fiscal_taxable_local_amount' => '0.0000',
                'fiscal_tax_base_amount' => '16.0000',
                'fiscal_tax_local_amount' => '0.0000',
                'fiscal_snapshot_at' => '2026-08-29T20:00:00Z',
                'opened_at' => '2026-08-29T19:00:00Z',
                'paid_at' => '2026-08-29T20:00:00Z',
                'closed_at' => '2026-08-29T20:00:00Z',
            ],
            'payments' => [],
        ]);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'pos.order.paid',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => 901,
            'payload_hash' => hash('sha256', $payload),
            'payload' => $payload,
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncEventApplier::class)->applyPending($tenant);

        $sale = DB::table('sales')->where('tenant_id', $tenant->id)->where('sync_source_id', 902)->first();
        $order = DB::table('pos_orders')->where('tenant_id', $tenant->id)->where('sync_source_id', 901)->first();
        $item = DB::table('sale_items')->where('tenant_id', $tenant->id)->where('sync_source_id', 903)->first();

        $this->assertSame(116.0, (float) $sale->total_base_amount);
        $this->assertSame(16.0, (float) $sale->fiscal_tax_base_amount);
        $this->assertSame(116.0, (float) $order->total_base_amount);
        $this->assertSame(16.0, (float) $order->fiscal_tax_base_amount);
        $this->assertSame('IVA16', $item->fiscal_tax_code);
        $this->assertSame(16.0, (float) $item->fiscal_tax_base_amount);
        $this->assertNotNull($sale->fiscal_snapshot_at);
        $this->assertNotNull($order->fiscal_snapshot_at);
        $this->assertNotNull($item->fiscal_snapshot_at);
    }
}
