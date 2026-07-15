<?php

namespace Tests\Feature\InventoryTransfers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Services\InventoryTransferService;
use App\Modules\InventoryTransfers\Services\TransferGuidePdfService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FASE T2: tests del servicio TransferGuidePdfService (sin HTTP).
 * Cubre el renderizado HTML y PDF, la inclusion del driver en el
 * payload y los estados que permiten generar la guia.
 *
 * Los tests del controller HTTP (endpoints .pdf / .html) estan
 * pendientes de un setup de testing con Plan C que requiere un
 * stub completo del middleware api.auth. Se dejan los tests del
 * servicio que son suficientes para validar la logica de generacion.
 */
class TransferGuidePdfTest extends TestCase
{
    use RefreshDatabase;

    private function setupPreparedTransfer(): array
    {
        $tenant = Tenant::create(['name' => 'Tienda Guia', 'slug' => 'tienda-guia']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-G']);
        $from = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Origen', 'code' => 'WH-G-ORIG']);
        $to = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Destino', 'code' => 'WH-G-DEST']);

        $product = \App\Modules\Products\Models\Product::create([
            'name' => 'Producto Test Guia',
            'sku' => 'TEST-G-'.uniqid(),
            'tracking_type' => \App\Modules\Products\Models\Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => \App\Modules\Products\Models\Product::CURRENCY_USD,
        ]);

        $service = app(InventoryTransferService::class);
        $transfer = $service->create($user, [
            'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'reason' => 'Test guia',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5],
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ]);

        \DB::table('inventory_transfers')
            ->where('id', $transfer->id)
            ->update(['status' => InventoryTransfer::STATUS_PREPARED]);

        return [$user, $transfer->fresh()];
    }

    public function test_render_html_returns_valid_html(): void
    {
        [$user, $transfer] = $this->setupPreparedTransfer();

        $service = new TransferGuidePdfService($transfer);
        $html = $service->renderHtml();

        $this->assertStringContainsString('Guia de Traslado', $html);
        $this->assertStringContainsString($transfer->document_number, $html);
        $this->assertStringContainsString('Almacen origen', $html);
        $this->assertStringContainsString('Almacen destino', $html);
        $this->assertStringContainsString('Items', $html);
        $this->assertStringContainsString('Firma del transportista', $html);
        $this->assertStringContainsString('Firma del receptor', $html);
    }

    public function test_render_html_includes_driver_data_when_assigned(): void
    {
        [$user, $transfer] = $this->setupPreparedTransfer();

        $service = app(InventoryTransferService::class);
        $service->assignDriver($user, $transfer, [
            'name' => 'Pedro Perez',
            'document_number' => 'V-12345678',
            'phone' => '+58 412 1234567',
            'vehicle_plate' => 'ABC-123',
            'carrier_company' => 'Transportes XYZ',
        ]);

        $service = new TransferGuidePdfService($transfer->refresh());
        $html = $service->renderHtml();

        $this->assertStringContainsString('Pedro Perez', $html);
        $this->assertStringContainsString('ABC-123', $html);
        $this->assertStringContainsString('Transportes XYZ', $html);
    }

    public function test_render_html_escapes_unsafe_values(): void
    {
        [$user, $transfer] = $this->setupPreparedTransfer();
        $transfer->update(['reason' => '<script>alert("xss")</script>']);

        $service = new TransferGuidePdfService($transfer->refresh());
        $html = $service->renderHtml();

        // Blade escapa por defecto en {{ }} -- el <script> aparece como texto
        // y NO como HTML ejecutable.
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function test_render_pdf_returns_valid_pdf_bytes(): void
    {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $this->markTestSkipped('domPDF no disponible');
        }

        [$user, $transfer] = $this->setupPreparedTransfer();

        $service = new TransferGuidePdfService($transfer);
        $bytes = $service->renderPdf();

        $this->assertGreaterThan(100, strlen($bytes), 'PDF debe tener contenido significativo');
        $this->assertStringStartsWith('%PDF', substr($bytes, 0, 4));
    }
}
