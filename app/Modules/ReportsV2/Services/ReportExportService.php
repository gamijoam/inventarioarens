<?php

namespace App\Modules\ReportsV2\Services;

use App\Modules\ReportsV2\Export\ReportExcelExport;
use App\Modules\ReportsV2\ReportDefinition;
use App\Modules\ReportsV2\ReportRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Genera la exportacion (CSV / XLSX / PDF) de un reporte V2 a partir de
 * los mismos datos agregados del runner.
 */
class ReportExportService
{
    public function __construct(
        private readonly ReportQueryService $runner,
        private readonly ReportRegistry $registry,
    ) {}

    public function export(string $code, array $filters, string $format): SymfonyResponse
    {
        $data = $this->runner->run($code, $filters);
        $definition = $this->registry->get($code);

        return match ($format) {
            'xlsx' => $this->xlsx($definition, $data),
            'pdf' => $this->pdf($definition, $data),
            default => $this->csv($definition, $data),
        };
    }

    private function csv(ReportDefinition $definition, array $data): SymfonyResponse
    {
        [$headings, $rows, $totalsRow] = $this->table($definition, $data);

        $handle = fopen('php://temp', 'w');
        fputcsv($handle, $headings);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fputcsv($handle, $totalsRow);
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return Response::make($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$this->filename($definition, 'csv')}\"",
        ]);
    }

    private function xlsx(ReportDefinition $definition, array $data): SymfonyResponse
    {
        [$headings, $rows, $totalsRow] = $this->table($definition, $data);

        return Excel::download(
            new ReportExcelExport($headings, $rows, $totalsRow),
            $this->filename($definition, 'xlsx'),
        );
    }

    private function pdf(ReportDefinition $definition, array $data): SymfonyResponse
    {
        [$headings, $rows, $totalsRow] = $this->table($definition, $data);

        $html = view('reports.v2-pdf', [
            'report' => $data['report'],
            'period' => $data['period'],
            'scope' => $data['scope'],
            'headings' => $headings,
            'rows' => $rows,
            'totalsRow' => $totalsRow,
        ])->render();

        return Pdf::loadHTML($html)->download($this->filename($definition, 'pdf'));
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>, 2: array<int, mixed>}
     */
    private function table(ReportDefinition $definition, array $data): array
    {
        $measures = array_keys($definition->measures);
        $dimensionLabel = $data['report']['dimension'];
        $headings = [$dimensionLabel, ...$measures];

        $rows = [];
        foreach ($data['rows'] as $row) {
            $rows[] = [$row['label'], ...array_map(fn (string $m): float => (float) $row[$m], $measures)];
        }

        $totalsRow = ['Totales', ...array_map(fn (string $m): float => (float) $data['totals'][$m], $measures)];

        return [$headings, $rows, $totalsRow];
    }

    private function filename(ReportDefinition $definition, string $extension): string
    {
        return "{$definition->code}.{$extension}";
    }
}
