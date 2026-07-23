<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\DataImport\Support\CsvParser;
use App\Modules\DataImport\Support\ImportRowResult;
use Generator;
use Illuminate\Support\Str;

abstract class BaseImporter implements ImporterInterface
{
    abstract public function entity(): string;

    abstract public function headers(): array;

    /**
     * Procesa una fila normalizada. Devuelve un ImportRowResult.
     *
     * @param  array<string, string|null>  $payload
     */
    abstract protected function processRow(array $payload, int $rowNumber): ImportRowResult;

    protected function normalizeSlug(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $slug = Str::slug(trim($value));

        return $slug === '' ? null : $slug;
    }

    protected function normalizeDecimal(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(' ', '', $normalized);

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    public function import(string $filePath): Generator
    {
        $parser = new CsvParser;

        foreach ($parser->parse($filePath) as $row) {
            $result = $this->processRow($row['payload'], $row['row_number']);
            yield $result;
        }
    }
}
