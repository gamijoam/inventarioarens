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

    public function import(string $filePath): Generator
    {
        foreach ($this->importRows($filePath) as [$rowNumber, $payload]) {
            yield $this->importRow($payload, $rowNumber);
        }
    }

    /**
     * Itera el CSV devolviendo el numero de fila y el payload normalizado
     * ANTES de procesarlo. Permite al orquestador decidir si debe saltar
     * la fila (reanudacion) sin ejecutar la logica de negocio.
     *
     * @return Generator<int, array{0: int, 1: array<string, string|null>}>
     */
    public function importRows(string $filePath): Generator
    {
        $parser = new CsvParser;

        foreach ($parser->parse($filePath) as $row) {
            yield [$row['row_number'], $row['payload']];
        }
    }

    /**
     * Procesa una fila individual normalizada.
     *
     * @param  array<string, string|null>  $payload
     */
    public function importRow(array $payload, int $rowNumber): ImportRowResult
    {
        return $this->processRow($payload, $rowNumber);
    }

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
}
