<?php

namespace App\Modules\ReportsV2\Export;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ReportExcelExport implements FromArray, WithHeadings
{
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $totalsRow,
    ) {}

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return [...$this->rows, $this->totalsRow];
    }
}
