<?php

namespace Tests\Unit\Support;

use App\Support\Pdf\PdfEngine;
use Tests\TestCase;

class PdfEngineTest extends TestCase
{
    public function test_generate_ticket_pdf_creates_valid_file(): void
    {
        $tmpDir = sys_get_temp_dir();
        $outFile = $tmpDir.'/test_ticket_'.uniqid().'.pdf';

        $data = [
            'company_name' => 'TIENDAS ARENS C.A.',
            'company_rif' => 'J-12345678-9',
            'document_no' => 'TKT-001',
            'date' => '2026-09-27 16:00',
            'items' => [
                ['description' => 'Arroz Mary 1kg', 'quantity' => 2, 'unit_price' => 1.50, 'total' => 3.00],
            ],
            'total_usd' => 3.00,
            'exchange_rate' => 46.50,
            'total_ves' => 139.50,
            'width_mm' => 80,
        ];

        $result = PdfEngine::generateTicket($data, $outFile);
        $this->assertNotEmpty($result);
        $this->assertFileExists($outFile);

        $content = file_get_contents($outFile);
        $this->assertStringStartsWith('%PDF-', $content);

        @unlink($outFile);
    }

    public function test_generate_invoice_pdf_creates_valid_file(): void
    {
        $tmpDir = sys_get_temp_dir();
        $outFile = $tmpDir.'/test_inv_'.uniqid().'.pdf';

        $data = [
            'company_name' => 'BALANZA PRO C.A.',
            'invoice_no' => 'FAC-999',
            'date' => '27/09/2026',
            'customer_name' => 'Cliente Mayorista',
            'items' => [
                ['sku' => 'QUESO-01', 'description' => 'Queso Paisa x Kg', 'quantity' => 5, 'unit_price' => 7.00, 'total' => 35.00],
            ],
            'subtotal_usd' => 35.00,
            'total_usd' => 35.00,
        ];

        $result = PdfEngine::generateInvoice($data, $outFile);
        $this->assertNotEmpty($result);
        $this->assertFileExists($outFile);

        $content = file_get_contents($outFile);
        $this->assertStringStartsWith('%PDF-', $content);

        @unlink($outFile);
    }
}
