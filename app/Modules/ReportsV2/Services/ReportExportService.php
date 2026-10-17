<?php

namespace App\Modules\ReportsV2\Services;

use App\Modules\ReportsV2\Export\ReportExcelExport;
use App\Modules\ReportsV2\ReportDefinition;
use App\Modules\ReportsV2\ReportRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ReportExportService
{
    private const MEASURE_LABELS = [
        'sales_total' => 'Ventas totales ($)',
        'sales_total_local' => 'Ventas totales (Bs)',
        'net_sales_total' => 'Ventas netas ($)',
        'reversed_total' => 'Devoluciones ($)',
        'reversed_local_total' => 'Devoluciones (Bs)',
        'reversal_count' => 'Cantidad de devoluciones',
        'sales_count' => 'Cantidad de ventas',
        'ticket_avg' => 'Ticket promedio ($)',
        'units' => 'Unidades',
        'amount' => 'Monto ($)',
        'amount_base' => 'Monto base ($)',
        'usd_paid' => 'Pagado ($)',
        'ves_paid' => 'Pagado (Bs)',
        'usd_equiv' => 'Equivalente ($)',
        'orders_count' => 'Cantidad de órdenes',
        'stock_qty' => 'Existencias',
        'stock_value' => 'Valor del inventario ($)',
        'balance' => 'Saldo',
        'count' => 'Cantidad',
    ];

    private const DIMENSION_LABELS = [
        'day' => 'Día',
        'week' => 'Semana',
        'month' => 'Mes',
        'product' => 'Producto',
        'category' => 'Categoría',
        'cashier' => 'Cajero / vendedor',
        'method' => 'Método de pago',
        'company' => 'Empresa',
        'warehouse' => 'Almacén',
        'customer' => 'Cliente',
        'supplier' => 'Proveedor',
    ];

    public function __construct(
        private readonly ReportQueryService $runner,
        private readonly ReportRegistry $registry,
    ) {}

    public function export(string $code, array $filters, string $format): SymfonyResponse
    {
        $data = $this->runner->run($code, $filters, true);
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
        fputcsv($handle, $headings, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        fputcsv($handle, $totalsRow, ',', '"', '\\');
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return Response::make("\xEF\xBB\xBF".$content, 200, [
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

    /** @return array{0: array<int, string>, 1: array<int, array<int, mixed>>, 2: array<int, mixed>} */
    private function table(ReportDefinition $definition, array $data): array
    {
        $measures = array_values(array_diff(array_keys($definition->measures), $definition->hiddenMeasures));
        $dimension = $data['report']['dimension'];
        $dimensionLabel = self::DIMENSION_LABELS[$dimension] ?? ucfirst(str_replace('_', ' ', $dimension));
        $showRate = $definition->localPairs !== [];
        $headings = [$dimensionLabel, ...array_map(
            fn (string $measure): string => self::MEASURE_LABELS[$measure] ?? ucfirst(str_replace('_', ' ', $measure)),
            $measures,
        ), ...($showRate ? ['Tasa Bs/USD'] : [])];

        $rows = [];
        foreach ($data['rows'] as $row) {
            $rows[] = [$this->safeText((string) $row['label']), ...array_map(
                fn (string $measure): float => (float) ($row[$measure] ?? 0),
                $measures,
            ), ...($showRate ? [($row['rate'] ?? null) === null ? '' : (float) $row['rate']] : [])];
        }
        $totalsRow = ['Totales', ...array_map(
            fn (string $measure): float => (float) ($data['totals'][$measure] ?? 0),
            $measures,
        ), ...($showRate ? [($data['rate'] ?? null) === null ? '' : (float) $data['rate']] : [])];

        return [$headings, $rows, $totalsRow];
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }

    private function filename(ReportDefinition $definition, string $extension): string
    {
        return "{$definition->code}.{$extension}";
    }
}
