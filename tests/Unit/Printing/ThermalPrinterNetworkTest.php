<?php

namespace Tests\Unit\Printing;

use App\Modules\Printing\Services\ThermalPrinterService;
use PHPUnit\Framework\TestCase;

class ThermalPrinterNetworkTest extends TestCase
{
    private ThermalPrinterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ThermalPrinterService;
    }

    public function test_escp_buffer_appends_cut_paper_command(): void
    {
        $buffer = $this->service->buildEscPos("Ticket\n", cutPaper: true, openDrawer: false);

        // GS V 0 (cut paper full): \x1D\x56\x00
        $this->assertStringEndsWith("\x1D\x56\x00", $buffer);
        $this->assertStringContainsString("Ticket\n", $buffer);
    }

    public function test_escp_buffer_appends_cash_drawer_command(): void
    {
        $buffer = $this->service->buildEscPos("Ticket\n", cutPaper: false, openDrawer: true);

        // ESC p 0 25 250 (pin 2): \x1B\x70\x00\x19\xFA
        $this->assertStringStartsWith("\x1B\x70\x00\x19\xFA", $buffer);
        $this->assertStringContainsString("Ticket\n", $buffer);
    }

    public function test_escp_buffer_applies_both_extras(): void
    {
        $buffer = $this->service->buildEscPos("Ticket\n", cutPaper: true, openDrawer: true);

        $this->assertStringStartsWith("\x1B\x70\x00\x19\xFA", $buffer);
        $this->assertStringEndsWith("\x1D\x56\x00", $buffer);
    }

    public function test_escp_buffer_without_extras_is_plain_text(): void
    {
        $buffer = $this->service->buildEscPos("Solo texto\n", cutPaper: false, openDrawer: false);

        $this->assertSame("Solo texto\n", $buffer);
    }

    public function test_print_network_rejects_missing_host(): void
    {
        $result = $this->service->print('Ticket', null, [
            'printer_type' => 'network',
            'network_host' => '',
            'network_port' => 9100,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('network_host', $result['message']);
    }

    public function test_print_network_sends_bytes_to_tcp_socket(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "No se pudo abrir servidor TCP: {$errstr}");

        $address = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $address);

        // Servidor no bloqueante: el client connect/write completa en el kernel
        // incluso antes de accept(); luego aceptamos y leemos el buffer.
        stream_set_blocking($server, false);

        try {
            $result = $this->service->print('Ticket de prueba', null, [
                'printer_type' => 'network',
                'network_host' => $host,
                'network_port' => (int) $port,
                'cut_paper' => true,
                'open_cash_drawer' => true,
            ]);
        } finally {
            // No cerrar el server hasta leer.
        }

        $this->assertTrue($result['ok'], json_encode($result));

        $received = '';
        $conn = @stream_socket_accept($server, 0);
        if ($conn !== false) {
            stream_set_blocking($conn, false);
            while (($chunk = fread($conn, 4096)) !== false && $chunk !== '') {
                $received .= $chunk;
            }
            fclose($conn);
        }
        fclose($server);

        $this->assertStringStartsWith("\x1B\x70\x00\x19\xFA", $received);
        $this->assertStringContainsString('Ticket de prueba', $received);
        $this->assertStringEndsWith("\x1D\x56\x00", $received);
    }

    public function test_print_network_returns_connection_error(): void
    {
        $result = $this->service->print('Ticket', null, [
            'printer_type' => 'network',
            'network_host' => '127.0.0.1',
            'network_port' => 1,
            'cut_paper' => false,
            'open_cash_drawer' => false,
        ]);

        $this->assertFalse($result['ok']);
    }
}
