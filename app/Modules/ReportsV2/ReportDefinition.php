<?php

namespace App\Modules\ReportsV2;

/**
 * Definicion declarativa de un reporte V2.
 *
 * La definicion describe la fuente, las medidas y las dimensiones del
 * reporte. El runner (`ReportQueryService`) compone una sola query SQL
 * agregada a partir de estos datos; ninguna expresion SQL se construye
 * desde input de usuario (todo es codigo confiable del registro).
 */
final class ReportDefinition
{
    /**
     * @param  array<string, string>  $measures  codigo => expr SQL sobre la base
     * @param  array<string, array{expr: string, label: string, join?: string}>  $dimensions
     * @param  array<string, string>  $equalityFilters  parametro => columna SQL
     * @param  array<string, string>  $averageMeasures  medida promedio => medida de peso (para totales)
     * @param  array<string, string>  $localPairs  medida local (Bs) => medida base (USD) para tasa
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $domain,
        public readonly string $permission,
        public readonly bool $orgSupported,
        public readonly string $base,
        public readonly ?string $dateColumn,
        public readonly string $statusSql,
        public readonly array $measures,
        public readonly string $defaultMeasure,
        public readonly array $dimensions,
        public readonly string $defaultDimension,
        public readonly array $equalityFilters = [],
        public readonly bool $lowStockFilter = false,
        public readonly array $averageMeasures = [],
        public readonly array $localPairs = [],
    ) {}

    /**
     * @param  array<string, string|null>  $filters
     */
    public function allowedDimension(string $dimension): bool
    {
        return isset($this->dimensions[$dimension]);
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    public function resolveDimension(array $filters): string
    {
        $requested = (string) ($filters['dimension'] ?? '');

        return $requested !== '' && $this->allowedDimension($requested)
            ? $requested
            : $this->defaultDimension;
    }
}
