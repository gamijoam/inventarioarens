<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Fiscal\Models\FiscalTaxRate;
use App\Modules\Products\Models\Product;
use Illuminate\Validation\ValidationException;

class FiscalSaleTaxService
{
    public function __construct(private readonly FiscalTaxCalculator $calculator) {}

    public function calculateProduct(Product $product, float|int|string $baseAmount, float|int|string $localAmount, ?FiscalTaxRate $taxRate = null): array
    {
        $baseAmount = $this->round((float) $baseAmount);
        $localAmount = $this->round((float) $localAmount);
        $taxRate ??= $product->fiscalTaxRate;

        if (! $taxRate) {
            return $this->emptyTax($baseAmount, $localAmount);
        }

        $base = $this->calculator->calculateLine($baseAmount, 1, $taxRate);
        $local = $this->calculator->calculateLine($localAmount, 1, $taxRate);

        return [
            'fiscal_tax_code' => $taxRate->code,
            'fiscal_tax_name' => $taxRate->name,
            'fiscal_tax_category' => $taxRate->category,
            'fiscal_tax_rate' => $this->round((float) $taxRate->rate),
            'fiscal_prices_include_tax' => false,
            'fiscal_taxable_base_amount' => $this->round($base['taxable_base']),
            'fiscal_taxable_local_amount' => $this->round($local['taxable_base']),
            'fiscal_exempt_base_amount' => $this->round($base['exempt_amount']),
            'fiscal_exempt_local_amount' => $this->round($local['exempt_amount']),
            'fiscal_exonerated_base_amount' => $this->round($base['exonerated_amount']),
            'fiscal_exonerated_local_amount' => $this->round($local['exonerated_amount']),
            'fiscal_non_taxable_base_amount' => $this->round($base['non_taxable_amount']),
            'fiscal_non_taxable_local_amount' => $this->round($local['non_taxable_amount']),
            'fiscal_tax_base_amount' => $this->round($base['tax_amount']),
            'fiscal_tax_local_amount' => $this->round($local['tax_amount']),
            'fiscal_total_base_amount' => $this->round($base['total_amount']),
            'fiscal_total_local_amount' => $this->round($local['total_amount']),
        ];
    }

    public function resolveRateForProduct(Product $product, ?int $taxRateId = null, ?string $taxRateCode = null): ?FiscalTaxRate
    {
        if ($taxRateId === null && $taxRateCode === null) {
            return $product->fiscalTaxRate;
        }

        $taxRate = FiscalTaxRate::query()
            ->where('tenant_id', $product->tenant_id)
            ->when($taxRateId !== null, fn ($query) => $query->whereKey($taxRateId))
            ->when($taxRateId === null, fn ($query) => $query->where('code', $taxRateCode))
            ->first();

        if (! $taxRate) {
            throw ValidationException::withMessages([
                'fiscal_tax_rate_id' => 'La alicuota fiscal seleccionada no existe para esta empresa.',
            ]);
        }

        return $taxRate;
    }

    public function aggregate(array $lines): array
    {
        $fields = [
            'fiscal_taxable_base_amount',
            'fiscal_taxable_local_amount',
            'fiscal_exempt_base_amount',
            'fiscal_exempt_local_amount',
            'fiscal_exonerated_base_amount',
            'fiscal_exonerated_local_amount',
            'fiscal_non_taxable_base_amount',
            'fiscal_non_taxable_local_amount',
            'fiscal_tax_base_amount',
            'fiscal_tax_local_amount',
            'fiscal_total_base_amount',
            'fiscal_total_local_amount',
        ];

        $totals = [];
        foreach ($fields as $field) {
            $totals[$field] = $this->round(array_sum(array_map(
                fn (array $line): float => (float) ($line[$field] ?? 0),
                $lines,
            )));
        }

        return $totals;
    }

    private function emptyTax(float $baseAmount, float $localAmount): array
    {
        return [
            'fiscal_tax_code' => null,
            'fiscal_tax_name' => null,
            'fiscal_tax_category' => null,
            'fiscal_tax_rate' => null,
            'fiscal_prices_include_tax' => false,
            'fiscal_taxable_base_amount' => 0.0,
            'fiscal_taxable_local_amount' => 0.0,
            'fiscal_exempt_base_amount' => 0.0,
            'fiscal_exempt_local_amount' => 0.0,
            'fiscal_exonerated_base_amount' => 0.0,
            'fiscal_exonerated_local_amount' => 0.0,
            'fiscal_non_taxable_base_amount' => 0.0,
            'fiscal_non_taxable_local_amount' => 0.0,
            'fiscal_tax_base_amount' => 0.0,
            'fiscal_tax_local_amount' => 0.0,
            'fiscal_total_base_amount' => $baseAmount,
            'fiscal_total_local_amount' => $localAmount,
        ];
    }

    private function round(float $value): float
    {
        return round($value, 4);
    }
}
