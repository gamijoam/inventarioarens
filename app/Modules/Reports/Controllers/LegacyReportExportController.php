<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Reports\Requests\LegacyReportExportRequest;
use App\Modules\Reports\Services\OperationalReportService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegacyReportExportController extends Controller
{
    private const COLUMNS = [
        'sales' => [
            'sale_label' => 'Venta',
            'confirmed_at' => 'Fecha',
            'customer_name' => 'Cliente',
            'cashier_label' => 'Cajero',
            'status' => 'Estado',
            'collection_label' => 'Cobranza',
            'total_base_amount' => 'Total ($)',
            'balance_base_amount' => 'Saldo ($)',
            'items_count' => 'Artículos',
        ],
        'cash' => [
            'cash_register_name' => 'Caja',
            'branch_name' => 'Sucursal',
            'cashier_name' => 'Cajero',
            'status' => 'Estado',
            'opening_base_amount' => 'Fondo ($)',
            'expected_base_amount' => 'Esperado ($)',
            'counted_base_amount' => 'Contado ($)',
            'difference_base_amount' => 'Diferencia ($)',
            'opened_at' => 'Apertura',
        ],
        'payments' => [
            'name' => 'Método de pago',
            'currency' => 'Moneda',
            'payments_count' => 'Cantidad de pagos',
            'amount_base' => 'Total ($)',
            'amount_local' => 'Total (Bs)',
            'missing_reference_count' => 'Referencias faltantes',
        ],
        'stock' => [
            'product_name' => 'Producto',
            'sku' => 'SKU',
            'warehouse_name' => 'Almacén',
            'quantity_available' => 'Disponible',
            'quantity_reserved' => 'Reservado',
            'quantity_damaged' => 'Dañado',
        ],
        'movements' => [
            'created_at' => 'Fecha',
            'type' => 'Tipo',
            'product_name' => 'Producto',
            'sku' => 'SKU',
            'warehouse_name' => 'Almacén',
            'quantity' => 'Cantidad',
            'reason' => 'Motivo',
        ],
    ];

    public function __invoke(
        string $report,
        LegacyReportExportRequest $request,
        OperationalReportService $reports,
    ): StreamedResponse {
        $filters = $request->filters();
        $rows = match ($report) {
            'sales' => $this->allOperationalPages(
                $filters,
                fn (array $pageFilters): array => $reports->salesDetail($pageFilters),
            ),
            'cash' => $this->allOperationalPages(
                $filters,
                fn (array $pageFilters): array => $reports->cashSessions($pageFilters, $request->user()),
            ),
            'payments' => $reports->paymentMethods($filters, 1000000),
            'stock' => $this->stockRows($filters),
            'movements' => $this->movementRows($filters),
            default => abort(404),
        };

        return $this->csv($report, $rows);
    }

    private function allOperationalPages(array $filters, callable $load): array
    {
        $rows = [];
        $page = 1;
        do {
            request()->query->set('page', (string) $page);
            $result = $load([...$filters, 'page' => $page, 'per_page' => 100]);
            array_push($rows, ...($result['rows'] ?? []));
            $lastPage = max(1, (int) ($result['meta']['last_page'] ?? 1));
            $page++;
        } while ($page <= $lastPage);

        return $rows;
    }

    private function stockRows(array $filters): array
    {
        $query = StockBalance::query()
            ->with(['warehouse', 'product'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, int $id) => $q->where('warehouse_id', $id))
            ->when($filters['product_id'] ?? null, fn ($q, int $id) => $q->where('product_id', $id))
            ->when($filters['branch_id'] ?? null, fn ($q, int $id) => $q->whereHas('warehouse', fn ($warehouse) => $warehouse->where('branch_id', $id)))
            ->when($filters['search'] ?? null, function ($q, string $search): void {
                $needle = mb_strtolower(trim($search));
                $q->whereHas('product', fn ($product) => $product
                    ->where(DB::raw('LOWER(name)'), 'like', '%'.$needle.'%')
                    ->orWhere(DB::raw('LOWER(sku)'), 'like', '%'.$needle.'%')
                    ->orWhere(DB::raw("LOWER(COALESCE(barcode, ''))"), 'like', '%'.$needle.'%'));
            })
            ->orderBy('warehouse_id')
            ->orderBy('product_id');

        $rows = [];
        $query->chunk(500, function ($balances) use (&$rows): void {
            foreach ($balances as $balance) {
                $rows[] = [
                    'product_name' => $balance->product?->name,
                    'sku' => $balance->product?->sku,
                    'warehouse_name' => $balance->warehouse?->name,
                    'quantity_available' => (float) $balance->quantity_available,
                    'quantity_reserved' => (float) $balance->quantity_reserved,
                    'quantity_damaged' => (float) $balance->quantity_damaged,
                ];
            }
        });

        return $rows;
    }

    private function movementRows(array $filters): array
    {
        $query = StockMovement::query()
            ->with(['warehouse', 'product'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, int $id) => $q->where('warehouse_id', $id))
            ->when($filters['product_id'] ?? null, fn ($q, int $id) => $q->where('product_id', $id))
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->when($filters['date_from'] ?? null, fn ($q, string $date) => $q->where('created_at', '>=', \App\Support\Time\BusinessDateRange::startOfDay($date)))
            ->when($filters['date_to'] ?? null, fn ($q, string $date) => $q->where('created_at', '<=', \App\Support\Time\BusinessDateRange::endOfDay($date)))
            ->when($filters['branch_id'] ?? null, fn ($q, int $id) => $q->whereHas('warehouse', fn ($warehouse) => $warehouse->where('branch_id', $id)))
            ->when($filters['search'] ?? null, function ($q, string $search): void {
                $needle = mb_strtolower(trim($search));
                $q->where(function ($sub) use ($needle): void {
                    $sub->whereHas('product', fn ($product) => $product
                        ->where(DB::raw('LOWER(name)'), 'like', '%'.$needle.'%')
                        ->orWhere(DB::raw('LOWER(sku)'), 'like', '%'.$needle.'%')
                        ->orWhere(DB::raw("LOWER(COALESCE(barcode, ''))"), 'like', '%'.$needle.'%'))
                        ->orWhere(DB::raw("LOWER(COALESCE(reason, ''))"), 'like', '%'.$needle.'%');
                });
            })
            ->latest('id');

        $rows = [];
        $query->chunk(500, function ($movements) use (&$rows): void {
            foreach ($movements as $movement) {
                $rows[] = [
                    'id' => $movement->id,
                    'created_at' => $movement->created_at?->toISOString(),
                    'type' => $movement->type,
                    'product_name' => $movement->product?->name,
                    'sku' => $movement->product?->sku,
                    'warehouse_name' => $movement->warehouse?->name,
                    'quantity' => (float) $movement->quantity,
                    'unit_cost' => $movement->unit_cost === null ? null : (float) $movement->unit_cost,
                    'reason' => $movement->reason,
                ];
            }
        });

        return $rows;
    }

    private function csv(string $report, array $rows): StreamedResponse
    {
        $columns = self::COLUMNS[$report] ?? [];
        $headings = array_values($columns);

        return response()->streamDownload(function () use ($report, $rows, $columns, $headings): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headings, ',', '"', '\\');
            foreach ($rows as $row) {
                $values = [];
                foreach (array_keys($columns) as $key) {
                    $value = $this->exportValue($report, $key, $row);
                    if (is_array($value) || is_object($value)) {
                        $value = '';
                    }
                    $values[] = is_string($value) ? $this->safeText($value) : $value;
                }
                fputcsv($handle, $values, ',', '"', '\\');
            }
            fclose($handle);
        }, 'reporte-'.$report.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function exportValue(string $report, string $key, array $row): mixed
    {
        if ($report === 'sales') {
            return match ($key) {
                'sale_label' => '#'.($row['id'] ?? '').' - '.($row['origin'] ?? 'Venta'),
                'cashier_label' => $row['cashier_name'] ?? $row['created_by_name'] ?? '-',
                'status' => $this->statusLabel((string) ($row['status'] ?? '')),
                'collection_label' => $this->statusLabel((string) ($row['collection']['status'] ?? 'none')),
                'balance_base_amount' => (float) ($row['collection']['balance_base_amount'] ?? 0),
                'items_count' => (int) ($row['items_count'] ?? count($row['items'] ?? [])),
                default => $row[$key] ?? null,
            };
        }

        if ($key === 'status') {
            return $this->statusLabel((string) ($row[$key] ?? ''));
        }
        if ($report === 'movements' && $key === 'type') {
            return $this->movementLabel((string) ($row[$key] ?? ''));
        }

        return $row[$key] ?? null;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Borrador',
            'confirmed' => 'Confirmada',
            'cancelled' => 'Cancelada',
            'voided', 'void' => 'Anulada',
            'paid' => 'Pagada',
            'pending' => 'Pendiente',
            'partial' => 'Parcial',
            'overdue' => 'Vencida',
            'open' => 'Abierta',
            'closed' => 'Cerrada',
            'none' => 'Sin CxC',
            default => $status,
        };
    }

    private function movementLabel(string $type): string
    {
        return match ($type) {
            'purchase' => 'Compra',
            'sale' => 'Venta',
            'sale_return' => 'Devolución',
            'adjustment_in' => 'Ajuste de entrada',
            'adjustment_out' => 'Ajuste de salida',
            'transfer_in' => 'Traslado de entrada',
            'transfer_out' => 'Traslado de salida',
            'transfer_request_in' => 'Entrada interempresa',
            'transfer_request_out' => 'Salida interempresa',
            'damaged' => 'Dañado',
            default => $type,
        };
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }
}
