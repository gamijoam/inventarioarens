<?php

namespace Tests\Feature\DataImport;

use App\Modules\DataImport\Support\CsvParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SpreadsheetCsvParserTest extends TestCase
{
    public function test_parser_reads_spreadsheet_headers_and_rows(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['SKU', 'Name'],
            ['SKU-001', 'Producto uno'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'data-import-').'.xlsx';

        try {
            (new Xlsx($spreadsheet))->save($path);
            $rows = iterator_to_array((new CsvParser)->parse($path));

            $this->assertSame(1, $rows[0]['row_number']);
            $this->assertSame([
                'sku' => 'SKU-001',
                'name' => 'Producto uno',
            ], $rows[0]['payload']);
        } finally {
            @unlink($path);
            $spreadsheet->disconnectWorksheets();
        }
    }
}
