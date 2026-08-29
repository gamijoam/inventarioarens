<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Fiscal\Models\FiscalTaxRate;

class FiscalTaxCalculator
{
    public function calculateLine(
        float|int|string $unitPrice,
        float|int|string $quantity,
        FiscalTaxRate $taxRate,
        float|int|string $discount = 0,
        bool $pricesIncludeTax = false,
    ): array {
        $grossAmount = $this->round((float) $unitPrice * (float) $quantity);
        $discountAmount = min($grossAmount, max(0, $this->round((float) $discount)));
        $netAmount = $this->round($grossAmount - $discountAmount);
        $rate = (float) $taxRate->rate;
        $taxAmount = 0.0;
        $taxableBase = 0.0;
        $totalAmount = $netAmount;

        if ($taxRate->category === FiscalTaxRate::CATEGORY_TAXABLE) {
            if ($pricesIncludeTax && $rate > 0) {
                $taxableBase = $this->round($netAmount / (1 + ($rate / 100)));
                $taxAmount = $this->round($netAmount - $taxableBase);
            } else {
                $taxableBase = $netAmount;
                $taxAmount = $this->round($taxableBase * ($rate / 100));
                $totalAmount = $this->round($netAmount + $taxAmount);
            }
        }

        return [
            'tax_code' => $taxRate->code,
            'tax_name' => $taxRate->name,
            'tax_category' => $taxRate->category,
            'tax_rate' => $this->round($rate),
            'gross_amount' => $grossAmount,
            'discount_amount' => $discountAmount,
            'net_amount' => $netAmount,
            'taxable_base' => $taxableBase,
            'exempt_amount' => $taxRate->category === FiscalTaxRate::CATEGORY_EXEMPT ? $netAmount : 0.0,
            'exonerated_amount' => $taxRate->category === FiscalTaxRate::CATEGORY_EXONERATED ? $netAmount : 0.0,
            'non_taxable_amount' => $taxRate->category === FiscalTaxRate::CATEGORY_NON_TAXABLE ? $netAmount : 0.0,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'prices_include_tax' => $pricesIncludeTax,
        ];
    }

    public function calculateDocument(array $lines, bool $pricesIncludeTax = false): array
    {
        $calculatedLines = array_map(
            fn (array $line): array => $this->calculateLine(
                unitPrice: $line['unit_price'],
                quantity: $line['quantity'],
                taxRate: $line['tax_rate'],
                discount: $line['discount'] ?? 0,
                pricesIncludeTax: $pricesIncludeTax,
            ),
            $lines,
        );

        return [
            'lines' => $calculatedLines,
            'gross_amount' => $this->sum($calculatedLines, 'gross_amount'),
            'discount_amount' => $this->sum($calculatedLines, 'discount_amount'),
            'net_amount' => $this->sum($calculatedLines, 'net_amount'),
            'taxable_base' => $this->sum($calculatedLines, 'taxable_base'),
            'exempt_amount' => $this->sum($calculatedLines, 'exempt_amount'),
            'exonerated_amount' => $this->sum($calculatedLines, 'exonerated_amount'),
            'non_taxable_amount' => $this->sum($calculatedLines, 'non_taxable_amount'),
            'tax_amount' => $this->sum($calculatedLines, 'tax_amount'),
            'total_amount' => $this->sum($calculatedLines, 'total_amount'),
        ];
    }

    private function sum(array $lines, string $key): float
    {
        return $this->round(array_sum(array_column($lines, $key)));
    }

    private function round(float $value): float
    {
        return round($value, 4);
    }
}
