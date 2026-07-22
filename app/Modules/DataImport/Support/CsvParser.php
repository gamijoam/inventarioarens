<?php

namespace App\Modules\DataImport\Support;

use Generator;
use InvalidArgumentException;

final class CsvParser
{
    public const MAX_ROWS = 5000;

    public const MAX_FILE_BYTES = 5242880;

    private const SEPARATORS = [',', ';', "\t", '|'];

    private string $separator = ',';

    private string $encoding = 'UTF-8';

    /**
     * @return Generator<int, array{row_number: int, payload: array<string, string|null>}>
     */
    public function parse(string $filePath): Generator
    {
        if (! is_file($filePath)) {
            throw new InvalidArgumentException("CSV file not found: {$filePath}");
        }

        $size = filesize($filePath);
        if ($size !== false && $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException(
                'CSV excede el tamano maximo permitido (5 MB).'
            );
        }

        $this->detectFormat($filePath);

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException("No se pudo abrir el CSV: {$filePath}");
        }

        try {
            $headers = null;
            $rowNumber = 0;
            $count = 0;

            while (($rawRow = fgetcsv($handle, 0, $this->separator, '"', '')) !== false) {
                if ($headers === null) {
                    $headers = $this->normalizeHeaders($rawRow);

                    continue;
                }

                $rowNumber++;
                $count++;

                if ($count > self::MAX_ROWS) {
                    throw new InvalidArgumentException(sprintf(
                        'CSV excede el maximo de %d filas. Dividi el archivo en lotes mas pequenos.',
                        self::MAX_ROWS,
                    ));
                }

                $payload = $this->combineHeaders($headers, $rawRow);

                yield [
                    'row_number' => $rowNumber,
                    'payload' => $payload,
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{separator: string, encoding: string}
     */
    public function detectedFormat(): array
    {
        return [
            'separator' => $this->separator,
            'encoding' => $this->encoding,
        ];
    }

    private function detectFormat(string $filePath): void
    {
        $sample = file_get_contents($filePath, false, null, 0, 8192);
        if ($sample === false) {
            throw new InvalidArgumentException("No se pudo leer el CSV: {$filePath}");
        }

        $bom = substr($sample, 0, 3);
        if ($bom === "\xEF\xBB\xBF") {
            $this->encoding = 'UTF-8-BOM';
            $sample = substr($sample, 3);
        } elseif (mb_detect_encoding($sample, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) === 'Windows-1252') {
            $this->encoding = 'Windows-1252';
            $sample = mb_convert_encoding($sample, 'UTF-8', 'Windows-1252');
        } else {
            $this->encoding = 'UTF-8';
        }

        $bestSep = ',';
        $bestCount = -1;
        $firstLine = strtok($sample, "\r\n") ?: $sample;

        foreach (self::SEPARATORS as $sep) {
            $count = substr_count($firstLine, $sep);
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestSep = $sep;
            }
        }

        $this->separator = $bestSep;
    }

    /**
     * @param  array<int, string>  $raw
     * @return array<int, string>
     */
    private function normalizeHeaders(array $raw): array
    {
        $headers = [];
        $seen = [];

        foreach ($raw as $i => $h) {
            $name = $this->cleanHeader((string) $h);
            if ($name === '') {
                $name = 'col_'.($i + 1);
            }
            $original = $name;
            $dup = 1;
            while (isset($seen[$name])) {
                $dup++;
                $name = $original.'_'.$dup;
            }
            $seen[$name] = true;
            $headers[] = $name;
        }

        return $headers;
    }

    private function cleanHeader(string $h): string
    {
        $h = trim($h);
        if ($this->encoding === 'Windows-1252') {
            $h = mb_convert_encoding($h, 'UTF-8', 'Windows-1252');
        }
        $h = mb_strtolower($h, 'UTF-8');
        $h = preg_replace('/[^a-z0-9_]+/u', '_', $h) ?? '';

        return trim($h, '_');
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $row
     * @return array<string, string|null>
     */
    private function combineHeaders(array $headers, array $row): array
    {
        $out = [];
        foreach ($headers as $i => $h) {
            $val = $row[$i] ?? null;
            if ($val === null) {
                $out[$h] = null;

                continue;
            }
            $val = $this->encoding === 'Windows-1252'
                ? mb_convert_encoding((string) $val, 'UTF-8', 'Windows-1252')
                : (string) $val;
            $val = trim($val);
            if ($val === '') {
                $val = null;
            }
            $out[$h] = $val;
        }

        return $out;
    }
}
