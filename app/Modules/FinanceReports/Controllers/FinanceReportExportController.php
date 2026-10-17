<?php

namespace App\Modules\FinanceReports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FinanceReports\Requests\FinanceReportExportRequest;
use App\Modules\FinanceReports\Services\FinanceReportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceReportExportController extends Controller
{
    public function __invoke(FinanceReportExportRequest $request, FinanceReportService $reports): StreamedResponse
    {
        $filters = $request->filters();
        $receivables = $reports->receivables($filters);
        $payables = $reports->payables($filters);

        return response()->streamDownload(function () use ($receivables, $payables): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Tipo de cuenta',
                'Cliente o proveedor',
                'Estado',
                'Saldo ($)',
            ], ',', '"', '\\');

            foreach ($receivables as $row) {
                $this->writeRow($handle, 'Por cobrar', $row, 'customer_name');
            }
            foreach ($payables as $row) {
                $this->writeRow($handle, 'Por pagar', $row, 'supplier_name');
            }

            fclose($handle);
        }, 'reporte-finanzas.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param resource $handle */
    private function writeRow($handle, string $kind, array $row, string $partyKey): void
    {
        fputcsv($handle, [
            $kind,
            $this->safeText((string) ($row[$partyKey] ?? '')),
            $this->statusLabel((string) ($row['status'] ?? '')),
            (float) ($row['balance_base_amount'] ?? 0),
        ], ',', '"', '\\');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Pendiente',
            'partial' => 'Parcial',
            'overdue' => 'Vencida',
            'paid' => 'Pagada',
            default => $status,
        };
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }
}
