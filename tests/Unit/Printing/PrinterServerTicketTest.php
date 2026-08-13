<?php

namespace Tests\Unit\Printing;

use App\Modules\Printing\Services\PrinterServer;
use PHPUnit\Framework\TestCase;

class PrinterServerTicketTest extends TestCase
{
    private PrinterServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new PrinterServer;
    }

    private function sampleTicket(array $profileOverrides = []): array
    {
        $profile = array_merge([
            'paper_width_mm' => 80,
            'logo_text' => 'Oscar Cell',
            'header_text' => 'Accesorios y reparaciones',
            'footer_text' => 'Gracias por su compra',
            'legal_text' => 'Documento no fiscal',
            'show_tenant_slug' => true,
            'show_sale_number' => true,
            'show_paid_at' => true,
            'show_cashier' => true,
            'show_cash_register' => true,
            'show_branch' => true,
            'show_customer' => true,
            'show_item_sku' => true,
            'show_item_discount' => true,
            'show_item_serials' => true,
            'show_warranty_summary' => true,
            'show_total_local' => true,
            'show_payment_rate' => true,
            'show_payment_reference' => true,
            'show_receivable_balance' => true,
            'show_non_fiscal_text' => true,
            'warranty_policy_text' => '',
        ], $profileOverrides);

        return [
            'tenant' => ['name' => 'Oscar Cell', 'slug' => 'oscar-cell'],
            'profile' => $profile,
            'pos_order' => [
                'id' => 10,
                'sale_id' => 5,
                'paid_at' => '2026-08-13T10:00:00Z',
                'customer_name' => 'Consumidor Final',
                'cashier_name' => 'Ana',
                'branch_name' => 'Principal',
                'cash_register_name' => 'Mostrador 1',
            ],
            'totals' => [
                'total_base_amount' => 7.33,
                'total_local_amount' => 3665,
                'paid_base_amount' => 7.33,
                'paid_local_amount' => 3665,
                'balance_base_amount' => 0,
            ],
            'items' => [[
                'product_name' => 'ACCESORIOS COSMETICOS',
                'sku' => 'ACC-001',
                'quantity' => 1,
                'unit_price' => 7.33,
                'total' => 7.33,
                'discount' => 0,
                'serials' => [['serial_number' => 'IMEI-001']],
                'warranty' => ['name' => 'Garantia 7 dias', 'duration_days' => 7, 'expires_at' => '2026-08-20', 'coverage_type' => 'standard'],
            ]],
            'payments' => [[
                'method' => 'Efectivo',
                'currency' => 'USD',
                'amount' => 7.33,
                'exchange_rate_type_code' => 'BCV',
                'exchange_rate' => 500,
                'reference' => 'REF-001',
            ]],
        ];
    }

    public function test_plain_ticket_uses_profile_logo_header_and_footer(): void
    {
        $text = $this->server->buildPlainTicket($this->sampleTicket());

        $this->assertStringContainsString('OSCAR CELL', $text);
        $this->assertStringContainsString('Accesorios y reparaciones', $text);
        $this->assertStringContainsString('Gracias por su compra', $text);
        $this->assertStringContainsString('oscar-cell', $text);
    }

    public function test_plain_ticket_uses_profile_sections(): void
    {
        $text = $this->server->buildPlainTicket($this->sampleTicket());

        $this->assertStringContainsString('Ticket POS #10', $text);
        $this->assertStringContainsString('Venta #5', $text);
        $this->assertStringContainsString('Cajero: Ana', $text);
        $this->assertStringContainsString('Caja: Mostrador 1', $text);
        $this->assertStringContainsString('Sucursal: Principal', $text);
        $this->assertStringContainsString('Cliente: Consumidor Final', $text);
        $this->assertStringContainsString('ACC-001', $text);
        $this->assertStringContainsString('IMEI/Serial: IMEI-001', $text);
        $this->assertStringContainsString('Total USD: $7.33', $text);
        $this->assertStringContainsString('Total VES: Bs 3.665,00', $text);
        $this->assertStringContainsString('BCV @ 500.00', $text);
        $this->assertStringContainsString('Ref: REF-001', $text);
        $this->assertStringContainsString('Documento no fiscal', $text);
    }

    public function test_plain_ticket_hides_disabled_sections(): void
    {
        $text = $this->server->buildPlainTicket($this->sampleTicket([
            'show_tenant_slug' => false,
            'show_sale_number' => false,
            'show_cashier' => false,
            'show_branch' => false,
            'show_item_sku' => false,
            'show_item_serials' => false,
            'show_total_local' => false,
            'show_payment_rate' => false,
            'show_payment_reference' => false,
            'show_non_fiscal_text' => false,
            'show_customer' => false,
        ]));

        $this->assertStringNotContainsString('oscar-cell', $text);
        $this->assertStringNotContainsString('Venta #', $text);
        $this->assertStringNotContainsString('Cajero:', $text);
        $this->assertStringNotContainsString('Sucursal:', $text);
        $this->assertStringNotContainsString('ACC-001', $text);
        $this->assertStringNotContainsString('IMEI/Serial', $text);
        $this->assertStringNotContainsString('Total VES', $text);
        $this->assertStringNotContainsString('BCV @', $text);
        $this->assertStringNotContainsString('Ref:', $text);
        $this->assertStringNotContainsString('Documento no fiscal', $text);
        $this->assertStringNotContainsString('Cliente:', $text);
        // Los datos esenciales del ticket siempre se mantienen.
        $this->assertStringContainsString('Ticket POS #10', $text);
        $this->assertStringContainsString('Total USD: $7.33', $text);
    }

    public function test_plain_ticket_uses_58mm_line_width(): void
    {
        $text = $this->server->buildPlainTicket($this->sampleTicket(['paper_width_mm' => 58]));

        foreach (explode("\n", $text) as $line) {
            $this->assertLessThanOrEqual(32, strlen($line), 'Linea excede 32 chars en 58mm: '.$line);
        }
    }
}
