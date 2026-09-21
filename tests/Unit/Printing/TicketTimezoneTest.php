<?php

namespace Tests\Unit\Printing;

use App\Modules\Printing\Services\PrinterServer;
use PHPUnit\Framework\TestCase;

class TicketTimezoneTest extends TestCase
{
    private PrinterServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new PrinterServer;
    }

    public function test_plain_ticket_formats_paid_at_in_venezuela_timezone(): void
    {
        // 11:27:27 UTC equivale exactamente a 07:27 AM en Venezuela (America/Caracas, UTC-4)
        $ticket = [
            'tenant' => ['name' => 'Repuestos Avilacar', 'slug' => 'repuestos-avilacar'],
            'profile' => [
                'paper_width_mm' => 80,
                'logo_text' => 'Repuestos Avilacar',
                'show_paid_at' => true,
            ],
            'pos_order' => [
                'id' => 43,
                'paid_at' => '2026-09-21T11:27:27.000000Z',
                'customer_name' => 'Consumidor Final',
            ],
            'totals' => [
                'total_base_amount' => 27.00,
                'paid_base_amount' => 27.00,
            ],
            'items' => [],
            'payments' => [],
        ];

        $text = $this->server->buildPlainTicket($ticket);

        // Debe contener la fecha y hora de Venezuela: 21/09/2026 07:27 AM
        $this->assertStringContainsString('Fecha: 21/09/2026 07:27 AM', $text);
        // NO debe contener la hora UTC 11:27
        $this->assertStringNotContainsString('11:27:27', $text);
    }
}
