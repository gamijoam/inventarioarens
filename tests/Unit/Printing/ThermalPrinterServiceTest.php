<?php

namespace Tests\Unit\Printing;

use App\Modules\Printing\Services\ThermalPrinterService;
use PHPUnit\Framework\TestCase;

class ThermalPrinterServiceTest extends TestCase
{
    private ThermalPrinterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ThermalPrinterService;
    }

    public function test_sanitize_removes_accents_and_special_chars(): void
    {
        $input = "Cajero: Pepé García ñandú\nTotal: 12.5 EUR — OK";

        $output = $this->service->sanitize($input);

        $this->assertStringContainsString('Cajero: Pepe Garcia nandu', $output);
        $this->assertStringContainsString('Total: 12.5 EUR - OK', $output);
    }

    public function test_sanitize_truncates_long_lines_to_64_chars(): void
    {
        $long = str_repeat('A', 100);

        $output = $this->service->sanitize($long);

        $this->assertLessThanOrEqual(64, strlen(trim($output)));
        $this->assertStringEndsWith('...', trim($output));
    }

    public function test_sanitize_keeps_short_plain_lines(): void
    {
        $input = "Hola mundo\nAdios";

        $output = $this->service->sanitize($input);

        $this->assertSame("Hola mundo\nAdios\n", $output);
    }

    public function test_build_command_windows_uses_powershell_out_printer(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Solo aplica en Windows.');
        }

        $cmd = $this->service->buildCommand('C:\\tmp\\ticket.txt', 'EPSON TM-T20');

        $this->assertStringStartsWith('powershell', $cmd);
        $this->assertStringContainsString('Out-Printer', $cmd);
        $this->assertStringContainsString('EPSON TM-T20', $cmd);
    }

    public function test_build_command_linux_uses_lpr(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Solo aplica en Linux.');
        }

        $cmd = $this->service->buildCommand('/tmp/ticket.txt', 'EPSON');

        $this->assertStringStartsWith('lpr -P ', $cmd);
    }

    public function test_print_without_printer_name_returns_error(): void
    {
        $result = $this->service->print("Ticket de prueba\n", null);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('printer_name', $result['message']);
    }
}
