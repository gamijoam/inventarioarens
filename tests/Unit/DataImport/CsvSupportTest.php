<?php

namespace Tests\Unit\DataImport;

use App\Modules\DataImport\Support\CsvParser;
use App\Modules\DataImport\Support\CsvReportWriter;
use App\Modules\DataImport\Support\ImportRowResult;
use PHPUnit\Framework\TestCase;

class CsvSupportTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/csv-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function writeCsv(string $name, string $content): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_parser_detects_comma_separator(): void
    {
        $path = $this->writeCsv('basic.csv', "sku,name\nSKU-1,Apple\nSKU-2,Banana\n");
        $parser = new CsvParser;

        $rows = iterator_to_array($parser->parse($path), false);

        $this->assertCount(2, $rows);
        $this->assertSame(['sku' => 'SKU-1', 'name' => 'Apple'], $rows[0]['payload']);
        $this->assertSame(['sku' => 'SKU-2', 'name' => 'Banana'], $rows[1]['payload']);
        $this->assertSame(1, $rows[0]['row_number']);
        $this->assertSame(2, $rows[1]['row_number']);
        $this->assertSame(',', $parser->detectedFormat()['separator']);
    }

    public function test_parser_detects_semicolon_separator(): void
    {
        $path = $this->writeCsv('semicolon.csv', "sku;name\nSKU-1;Apple\nSKU-2;Banana\n");
        $parser = new CsvParser;

        $rows = iterator_to_array($parser->parse($path), false);

        $this->assertCount(2, $rows);
        $this->assertSame(';', $parser->detectedFormat()['separator']);
        $this->assertSame('SKU-1', $rows[0]['payload']['sku']);
    }

    public function test_parser_strips_utf8_bom(): void
    {
        $bom = "\xEF\xBB\xBF";
        $path = $this->writeCsv('bom.csv', $bom."sku,name\nSKU-1,Apple\n");
        $parser = new CsvParser;

        $rows = iterator_to_array($parser->parse($path), false);

        $this->assertSame('SKU-1', $rows[0]['payload']['sku']);
        $this->assertSame('UTF-8-BOM', $parser->detectedFormat()['encoding']);
    }

    public function test_parser_normalizes_headers(): void
    {
        $path = $this->writeCsv('weird.csv', "  SKU , Name (of product),Descripcion\nSKU-1,X,Y\n");
        $parser = new CsvParser;

        $rows = iterator_to_array($parser->parse($path), false);

        $this->assertSame('SKU-1', $rows[0]['payload']['sku']);
        $this->assertSame('X', $rows[0]['payload']['name_of_product']);
        $this->assertSame('Y', $rows[0]['payload']['descripcion']);
    }

    public function test_parser_converts_empty_strings_to_null(): void
    {
        $path = $this->writeCsv('empty.csv', "sku,name,barcode\nSKU-1,,12345\n");
        $parser = new CsvParser;

        $rows = iterator_to_array($parser->parse($path), false);

        $this->assertNull($rows[0]['payload']['name']);
        $this->assertSame('12345', $rows[0]['payload']['barcode']);
    }

    public function test_parser_handles_quoted_strings_with_separators(): void
    {
        $path = $this->writeCsv('quoted.csv', "sku,name\n\"SKU-1\",\"Apple, red\"\n");
        $parser = new CsvParser;

        $rows = iterator_to_array($parser->parse($path), false);

        $this->assertSame('Apple, red', $rows[0]['payload']['name']);
    }

    public function test_parser_handles_duplicate_headers(): void
    {
        $path = $this->writeCsv('dup.csv', "name,name,name\nA,B,C\n");
        $parser = new CsvParser;

        $rows = iterator_to_array($parser->parse($path), false);

        $this->assertSame('A', $rows[0]['payload']['name']);
        $this->assertSame('B', $rows[0]['payload']['name_2']);
        $this->assertSame('C', $rows[0]['payload']['name_3']);
    }

    public function test_parser_throws_when_max_rows_exceeded(): void
    {
        $lines = ['sku,name'];
        for ($i = 1; $i <= 5001; $i++) {
            $lines[] = "SKU-{$i},Item {$i}";
        }
        $path = $this->writeCsv('big.csv', implode("\n", $lines));

        $parser = new CsvParser;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV excede el maximo');

        iterator_to_array($parser->parse($path), false);
    }

    public function test_parser_works_as_streaming_generator(): void
    {
        $lines = ['sku,name'];
        for ($i = 1; $i <= 100; $i++) {
            $lines[] = "SKU-{$i},Item {$i}";
        }
        $path = $this->writeCsv('stream.csv', implode("\n", $lines));

        $parser = new CsvParser;
        $count = 0;
        foreach ($parser->parse($path) as $row) {
            $count++;
            if ($count === 1) {
                $this->assertSame('SKU-1', $row['payload']['sku']);
            }
            if ($count === 100) {
                $this->assertSame('SKU-100', $row['payload']['sku']);
            }
        }
        $this->assertSame(100, $count);
    }

    public function test_report_writer_emits_valid_csv(): void
    {
        $writer = new CsvReportWriter;
        $rows = [
            [
                'row_number' => 1,
                'entity' => 'products',
                'status' => ImportRowResult::STATUS_OK,
                'natural_key' => 'SKU-1',
                'resulting_id' => 42,
                'errors' => [],
            ],
            [
                'row_number' => 2,
                'entity' => 'products',
                'status' => ImportRowResult::STATUS_SKIPPED,
                'natural_key' => 'SKU-2',
                'resulting_id' => null,
                'errors' => [],
                'message' => 'SKU ya existe',
            ],
            [
                'row_number' => 3,
                'entity' => 'products',
                'status' => ImportRowResult::STATUS_FAILED,
                'natural_key' => 'SKU-3',
                'resulting_id' => null,
                'errors' => ['sku' => ['SKU requerido'], 'name' => ['nombre requerido']],
            ],
        ];

        $csv = $writer->write($rows);

        $lines = explode("\n", trim($csv));
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('fila,entidad,estado,clave_natural', $lines[0]);
        $this->assertStringContainsString('1,products,ok,SKU-1,42,', $lines[1]);
        $this->assertStringContainsString('2,products,skipped,SKU-2,,', $lines[2]);
        $this->assertStringContainsString('sku: SKU requerido', $lines[3]);
        $this->assertStringContainsString('name: nombre requerido', $lines[3]);
    }

    public function test_import_row_result_factory_methods(): void
    {
        $ok = ImportRowResult::ok(42, 'SKU-1', 'creado');
        $this->assertSame('ok', $ok->status);
        $this->assertSame(42, $ok->resultingId);
        $this->assertSame('SKU-1', $ok->naturalKey);
        $this->assertTrue($ok->isOk());

        $skipped = ImportRowResult::skipped('ya existe', 'SKU-2');
        $this->assertSame('skipped', $skipped->status);
        $this->assertSame('ya existe', $skipped->message);
        $this->assertTrue($skipped->isSkipped());

        $failed = ImportRowResult::failed(['sku' => 'requerido'], 'SKU-3');
        $this->assertSame('failed', $failed->status);
        $this->assertSame(['sku' => 'requerido'], $failed->errors);
        $this->assertTrue($failed->isFailed());
    }
}
