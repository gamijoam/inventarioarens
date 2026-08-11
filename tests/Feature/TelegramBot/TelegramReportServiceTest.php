<?php

namespace Tests\Feature\TelegramBot;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\TelegramBot\Services\TelegramReportService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_text_includes_sales_stock_and_finance(): void
    {
        $tenant = Tenant::create(['name' => 'Mi Empresa', 'slug' => 'mi-empresa']);
        app(TenantManager::class)->set($tenant);

        $branch = Branch::create(['name' => 'B', 'code' => 'BR-R']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'W', 'code' => 'WH-R']);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Producto X',
            'sku' => 'RX-001',
            'tracking_type' => 'quantity',
            'min_stock' => 2,
            'sale_currency' => 'USD',
            'is_active' => true,
        ]);
        StockBalance::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_available' => 1,
        ]);

        $text = app(TelegramReportService::class)->summaryText($tenant);

        $this->assertStringContainsString('Mi Empresa', $text);
        $this->assertStringContainsString('Ventas hoy', $text);
        $this->assertStringContainsString('Stock bajo', $text);
        $this->assertStringContainsString('Producto X', $text);
    }
}
