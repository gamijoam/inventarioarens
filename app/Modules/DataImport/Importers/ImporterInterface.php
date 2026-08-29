<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\DataImport\Support\ImportRowResult;
use Generator;

interface ImporterInterface
{
    public function entity(): string;

    /**
     * Headers normalizados (lowercase, snake_case). El parser ya normaliza,
     * pero el importer documenta el orden esperado y permite sugerir.
     *
     * @return array<int, string>
     */
    public function headers(): array;

    /**
     * Procesa un archivo CSV fila por fila. Generador para no cargar
     * todo en memoria. Cada yield devuelve el resultado del row.
     *
     * @return Generator<int, ImportRowResult>
     */
    public function import(string $filePath): Generator;

    /**
     * Clave natural que identifica de forma unica una fila importada
     * (sku, code, slug, documento, etc.). Se usa para reanudar un import
     * omitiendo filas que ya se procesaron con exito.
     *
     * @param  array<string, string|null>  $payload
     */
    public function naturalKey(array $payload): string;
}
