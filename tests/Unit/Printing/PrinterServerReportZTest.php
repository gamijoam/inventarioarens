<?php

namespace Tests\Unit\Printing;

use App\Modules\Printing\Services\PrinterServer;
use PHPUnit\Framework\TestCase;

class PrinterServerReportZTest extends TestCase
{
    private PrinterServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new PrinterServer;
    }

    private function sampleZ(): array
    {
        return [
            'doc' => 'report_z',
            'z_number' => 3,
            'tenant' => ['name' => 'Oscar Cell', 'slug' => 'oscar-cell'],
            'cash_register' => 'CAJA1',
            'branch' => 'Principal',
            'cashier' => 'Ana',
            'opened_at' => '2026-08-18T08:00:00Z',
            'closed_at' => '2026-08-18T20:00:00Z',
            'totals' => [
                'orders_count' => 12,
                'paid_base_amount' => 500,
                'paid_local_amount' => 37500,
                'difference_cash_usd' => -2,
                'difference_cash_ves' => 0,
            ],
            'payments' => [
                ['name' => 'Efectivo', 'method' => 'cash', 'currency' => 'USD', 'amount_base' => 300, 'amount_local' => 22500, 'exchange_rate' => 75],
                ['name' => 'Pago Movil', 'method' => 'mobile_payment', 'currency' => 'VES', 'amount_base' => 200, 'amount_local' => 15000, 'exchange_rate' => 75],
            ],
            'profile' => [
                'paper_width_mm' => 80,
                'logo_text' => 'Oscar Cell',
                'footer_text' => 'Gracias por su preferencia',
                'legal_text' => 'Documento no fiscal',
                'show_non_fiscal_text' => true,
            ],
        ];
    }

    public function test_build_plain_report_z_renders_z_header_totals_and_payments(): void
    {
        $text = $this->server->buildPlainReportZ($this->sampleZ());

        $this->assertStringContainsString('REPORTE Z', $text);
        $this->assertStringContainsString('Z #3', $text);
        $this->assertStringContainsString('Caja: CAJA1', $text);
        $this->assertStringContainsString('Cajero: Ana', $text);
        $this->assertStringContainsString('Tickets: 12', $text);
        $this->assertStringContainsString('Total USD: $500.00', $text);
        $this->assertStringContainsString('Total VES: Bs 37.500,00', $text);
        $this->assertStringContainsString('Efectivo: $300.00', $text);
        $this->assertStringContainsString('Pago Movil: Bs 15.000,00', $text);
        $this->assertStringContainsString('Dif efectivo USD: $-2.00', $text);
        $this->assertStringNotContainsString('Ticket POS', $text);
    }

    public function test_build_plain_report_z_handles_empty_payments(): void
    {
        $z = $this->sampleZ();
        $z['payments'] = [];

        $text = $this->server->buildPlainReportZ($z);

        $this->assertStringContainsString('Sin pagos registrados.', $text);
    }
}
