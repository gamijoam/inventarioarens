<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\DataImport\Support\CsvParser;
use App\Modules\DataImport\Support\ImportRowResult;
use Generator;

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
        $parser = new CsvParser;

        foreach ($parser->parse($filePath) as $row) {
            $result = $this->processRow($row['payload'], $row['row_number']);
            yield $result;
        }
    }
}
