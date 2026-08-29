<?php

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Models\FiscalTaxRate;
use App\Modules\Fiscal\Services\FiscalTaxCalculator;
use Tests\TestCase;

class FiscalTaxCalculatorTest extends TestCase
{
    public function test_taxable_line_with_tax_excluded_from_price(): void
    {
        $result = app(FiscalTaxCalculator::class)->calculateLine(
            unitPrice: 100,
            quantity: 2,
            taxRate: $this->rate('IVA16', 16, 'taxable'),
            discount: 10,
        );

        $this->assertSame(190.0, $result['net_amount']);
        $this->assertSame(190.0, $result['taxable_base']);
        $this->assertSame(30.4, $result['tax_amount']);
        $this->assertSame(220.4, $result['total_amount']);
        $this->assertSame(0.0, $result['exempt_amount']);
    }

    public function test_taxable_line_with_tax_included_extracts_tax_from_total(): void
    {
        $result = app(FiscalTaxCalculator::class)->calculateLine(
            unitPrice: 116,
            quantity: 1,
            taxRate: $this->rate('IVA16', 16, 'taxable'),
            pricesIncludeTax: true,
        );

        $this->assertSame(100.0, $result['taxable_base']);
        $this->assertSame(16.0, $result['tax_amount']);
        $this->assertSame(116.0, $result['total_amount']);
    }

    public function test_exempt_and_exonerated_lines_have_zero_tax_and_preserve_their_base(): void
    {
        $calculator = app(FiscalTaxCalculator::class);
        $exempt = $calculator->calculateLine(50, 2, $this->rate('EXENTO', 0, 'exempt'));
        $exonerated = $calculator->calculateLine(50, 2, $this->rate('EXONERADO', 0, 'exonerated'));

        $this->assertSame(100.0, $exempt['exempt_amount']);
        $this->assertSame(0.0, $exempt['tax_amount']);
        $this->assertSame(100.0, $exempt['total_amount']);
        $this->assertSame(100.0, $exonerated['exonerated_amount']);
        $this->assertSame(0.0, $exonerated['tax_amount']);
    }

    public function test_document_aggregates_taxable_exempt_and_exonerated_lines(): void
    {
        $result = app(FiscalTaxCalculator::class)->calculateDocument([
            [
                'unit_price' => 100,
                'quantity' => 1,
                'tax_rate' => $this->rate('IVA16', 16, 'taxable'),
            ],
            [
                'unit_price' => 50,
                'quantity' => 2,
                'tax_rate' => $this->rate('EXENTO', 0, 'exempt'),
            ],
            [
                'unit_price' => 25,
                'quantity' => 1,
                'tax_rate' => $this->rate('EXONERADO', 0, 'exonerated'),
            ],
        ]);

        $this->assertCount(3, $result['lines']);
        $this->assertSame(100.0, $result['taxable_base']);
        $this->assertSame(100.0, $result['exempt_amount']);
        $this->assertSame(25.0, $result['exonerated_amount']);
        $this->assertSame(16.0, $result['tax_amount']);
        $this->assertSame(241.0, $result['total_amount']);
    }

    private function rate(string $code, float $rate, string $category): FiscalTaxRate
    {
        return new FiscalTaxRate([
            'code' => $code,
            'name' => $code,
            'rate' => $rate,
            'category' => $category,
        ]);
    }
}
