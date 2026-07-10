<?php

namespace App\Modules\AdminPortal\Services;

use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminTransferService
{
    public function index(array $filters): array
    {
        $tenant = app(TenantManager::class)->require();
        $limit = max(10, min((int) ($filters['limit'] ?? 25), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = $this->baseQuery($tenant->id, $filters);
        $total = (clone $query)->count();
        $rows = (clone $query)
            ->orderByDesc('inventory_transfers.processed_at')
            ->orderByDesc('inventory_transfers.id')
            ->forPage($page, $limit)
            ->get($this->columns())
            ->map(fn ($row): array => $this->mapTransfer($row))
            ->all();

        return [
            'data' => $rows,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'from' => $total === 0 ? 0 : (($page - 1) * $limit) + 1,
                'to' => min($page * $limit, $total),
                'has_previous' => $page > 1,
                'has_next' => $page * $limit < $total,
            ],
        ];
    }

    public function summary(array $filters = []): array
    {
        $tenant = app(TenantManager::class)->require();
        $base = $this->baseQuery($tenant->id, $filters);

        $byStatus = (clone $base)
            ->select('inventory_transfers.status', DB::raw('count(*) as total'))
            ->groupBy('inventory_transfers.status')
            ->pluck('total', 'inventory_transfers.status')
            ->all();

        $counts = [];
        foreach (InventoryTransfer::ALL_STATUSES as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        $total = array_sum($counts);
        $inFlight = 0;
        foreach (InventoryTransfer::IN_FLIGHT_STATUSES as $status) {
            $inFlight += $counts[$status] ?? 0;
        }

        $withDifferences = 0;
        foreach (InventoryTransfer::DIFFERENCES_STATUSES as $status) {
            $withDifferences += $counts[$status] ?? 0;
        }

        return [
            'by_status' => $counts,
            'status_labels' => $this->statusLabels(),
            'total' => $total,
            'in_flight' => $inFlight,
            'with_differences' => $withDifferences,
            'warehouses' => $this->warehouseOptions($tenant->id),
            'generated_at' => now()->toISOString(),
        ];
    }

    private function warehouseOptions(int $tenantId): array
    {
        return DB::table('warehouses')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'code' => $row->code,
            ])
            ->all();
    }

    private function baseQuery(int $tenantId, array $filters): Builder
    {
        $query = DB::table('inventory_transfers')
            ->leftJoin('warehouses as from_wh', 'from_wh.id', '=', 'inventory_transfers.from_warehouse_id')
            ->leftJoin('warehouses as to_wh', 'to_wh.id', '=', 'inventory_transfers.to_warehouse_id')
            ->where('inventory_transfers.tenant_id', $tenantId);

        $statuses = $filters['statuses'] ?? [];
        if (! empty($statuses)) {
            $query->whereIn('inventory_transfers.status', $statuses);
        }

        if (! empty($filters['warehouse_id'])) {
            $wid = (int) $filters['warehouse_id'];
            $query->where(function ($q) use ($wid): void {
                $q->where('inventory_transfers.from_warehouse_id', $wid)
                    ->orWhere('inventory_transfers.to_warehouse_id', $wid);
            });
        }

        if (! empty($filters['date_from'])) {
            $from = Carbon::parse($filters['date_from'])->startOfDay();
            $query->where('inventory_transfers.processed_at', '>=', $from);
        }

        if (! empty($filters['date_to'])) {
            $to = Carbon::parse($filters['date_to'])->endOfDay();
            $query->where('inventory_transfers.processed_at', '<=', $to);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($like, $search): void {
                $q->whereRaw('lower(coalesce(inventory_transfers.document_number, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(inventory_transfers.guide_number, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(inventory_transfers.reference, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(inventory_transfers.notes, \'\')) like ?', [$like]);

                if (ctype_digit($search)) {
                    $q->orWhere('inventory_transfers.id', (int) $search);
                }
            });
        }

        return $query;
    }

    private function columns(): array
    {
        return [
            'inventory_transfers.id',
            'inventory_transfers.sequence',
            'inventory_transfers.document_number',
            'inventory_transfers.guide_number',
            'inventory_transfers.type',
            'inventory_transfers.validation_mode',
            'inventory_transfers.status',
            'inventory_transfers.resolution_status',
            'inventory_transfers.from_warehouse_id',
            'inventory_transfers.to_warehouse_id',
            'inventory_transfers.reason',
            'inventory_transfers.reference',
            'inventory_transfers.notes',
            'inventory_transfers.processed_at',
            'inventory_transfers.prepared_at',
            'inventory_transfers.dispatched_at',
            'inventory_transfers.received_at',
            'inventory_transfers.cancelled_at',
            'inventory_transfers.created_at',
            DB::raw('coalesce(from_wh.name, \'Origen\') as from_warehouse_name'),
            DB::raw('coalesce(to_wh.name, \'Destino\') as to_warehouse_name'),
        ];
    }

    private function mapTransfer(object $row): array
    {
        $itemsCount = (int) DB::table('inventory_transfer_items')
            ->where('inventory_transfer_id', $row->id)
            ->count();

        $differencesCount = (int) DB::table('inventory_transfer_items')
            ->where('inventory_transfer_id', $row->id)
            ->where('difference_quantity', '!=', 0)
            ->count();

        return [
            'id' => (int) $row->id,
            'document_number' => $row->document_number,
            'guide_number' => $row->guide_number,
            'type' => $row->type,
            'validation_mode' => $row->validation_mode,
            'status' => $row->status,
            'status_label' => $this->statusLabel($row->status),
            'resolution_status' => $row->resolution_status,
            'from_warehouse_id' => (int) $row->from_warehouse_id,
            'to_warehouse_id' => (int) $row->to_warehouse_id,
            'from_warehouse_name' => $row->from_warehouse_name,
            'to_warehouse_name' => $row->to_warehouse_name,
            'reason' => $row->reason,
            'reference' => $row->reference,
            'items_count' => $itemsCount,
            'differences_count' => $differencesCount,
            'processed_at' => $row->processed_at,
            'prepared_at' => $row->prepared_at,
            'dispatched_at' => $row->dispatched_at,
            'received_at' => $row->received_at,
            'cancelled_at' => $row->cancelled_at,
            'created_at' => $row->created_at,
        ];
    }

    private function statusLabel(string $status): string
    {
        return $this->statusLabels()[$status] ?? ucfirst($status);
    }

    private function statusLabels(): array
    {
        return [
            InventoryTransfer::STATUS_REQUESTED => 'Solicitado',
            InventoryTransfer::STATUS_IN_PREPARATION => 'En preparacion',
            InventoryTransfer::STATUS_PREPARED => 'Preparado',
            InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES => 'Preparado con diferencias',
            InventoryTransfer::STATUS_DISPATCHED => 'Despachado',
            InventoryTransfer::STATUS_IN_RECEPTION => 'En recepcion',
            InventoryTransfer::STATUS_COMPLETED => 'Completado',
            InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES => 'Completado con diferencias',
            InventoryTransfer::STATUS_REJECTED => 'Rechazado',
            InventoryTransfer::STATUS_CANCELLED => 'Cancelado',
        ];
    }
}
