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

    public function detail(InventoryTransfer $transfer): array
    {
        $transfer->load(['fromWarehouse', 'toWarehouse', 'guide', 'items.product', 'canceller', 'resolver']);

        $rows = $this->baseQuery(app(TenantManager::class)->require()->id, [])
            ->where('inventory_transfers.id', $transfer->id)
            ->get($this->columns())
            ->map(fn ($row): array => $this->mapTransfer($row))
            ->all();

        $row = $rows[0] ?? null;
        if ($row === null) {
            abort(404, 'Traslado no encontrado.');
        }

        $items = $transfer->items->map(function ($item): array {
            $product = $item->product;
            $serialized = $product?->tracking_type === 'serialized';

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $product?->name ?? 'Producto eliminado',
                'product_sku' => $product?->sku ?? '',
                'tracking_type' => $product?->tracking_type,
                'serialized' => $serialized,
                'quantity' => (float) $item->quantity,
                'requested_quantity' => $item->requested_quantity === null ? null : (float) $item->requested_quantity,
                'prepared_quantity' => $item->prepared_quantity === null ? null : (float) $item->prepared_quantity,
                'received_quantity' => $item->received_quantity === null ? null : (float) $item->received_quantity,
                'difference_quantity' => (float) $item->difference_quantity,
                'difference_reason' => $item->difference_reason,
                'difference_notes' => $item->difference_notes,
                'resolution_status' => $item->resolution_status,
                'resolution_notes' => $item->resolution_notes,
                'resolved_at' => $item->resolved_at?->toISOString(),
                'product_unit_ids' => $item->product_unit_ids ?? [],
                'prepared_product_unit_ids' => $item->prepared_product_unit_ids ?? [],
                'received_product_unit_ids' => $item->received_product_unit_ids ?? [],
            ];
        })->all();

        $availableActions = $this->availableActionsFor($row['status']);

        return [
            'transfer' => $row,
            'items' => $items,
            'available_actions' => $availableActions,
            'canceller' => $transfer->canceller ? [
                'id' => $transfer->canceller->id,
                'name' => $transfer->canceller->name,
            ] : null,
            'resolver' => $transfer->resolver ? [
                'id' => $transfer->resolver->id,
                'name' => $transfer->resolver->name,
            ] : null,
        ];
    }

    /**
     * Devuelve la lista de acciones que el admin puede tomar en este estado.
     * Se usa desde el portal para mostrar/ocultar botones sin re-chequear permisos.
     */
    public function availableActionsFor(string $status): array
    {
        return match ($status) {
            InventoryTransfer::STATUS_REQUESTED,
            InventoryTransfer::STATUS_IN_PREPARATION => ['prepare', 'cancel'],
            InventoryTransfer::STATUS_PREPARED,
            InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES => ['dispatch', 'cancel'],
            InventoryTransfer::STATUS_DISPATCHED,
            InventoryTransfer::STATUS_IN_RECEPTION => ['receive', 'cancel'],
            InventoryTransfer::STATUS_COMPLETED_WITH_DIFFERENCES => ['resolve_differences'],
            default => [],
        };
    }

    public function export(array $filters): array
    {
        $tenant = app(TenantManager::class)->require();
        $stamp = now()->format('Ymd-His');

        $baseColumns = $this->columns();
        $baseColumns[] = DB::raw('(select count(*) from inventory_transfer_items where inventory_transfer_items.inventory_transfer_id = inventory_transfers.id) as items_count');
        $baseColumns[] = DB::raw("(select count(*) from inventory_transfer_items where inventory_transfer_items.inventory_transfer_id = inventory_transfers.id and inventory_transfer_items.difference_quantity != 0) as differences_count");

        $rows = $this->baseQuery($tenant->id, $filters)
            ->orderByDesc('inventory_transfers.id')
            ->limit(2000)
            ->get($baseColumns)
            ->map(fn ($row): array => [
                $row->id,
                $row->document_number,
                $row->guide_number,
                $row->type,
                $row->validation_mode,
                $this->statusLabel($row->status),
                $row->from_warehouse_name,
                $row->to_warehouse_name,
                (int) $row->items_count,
                (int) $row->differences_count,
                $row->reason ?: '',
                $row->reference ?: '',
                $row->processed_at,
                $row->prepared_at,
                $row->dispatched_at,
                $row->received_at,
                $row->cancelled_at,
            ])
            ->all();

        return [
            'filename' => "traslados-{$tenant->slug}-{$stamp}.csv",
            'headers' => [
                'ID', 'Documento', 'Guia', 'Tipo', 'Modo validacion', 'Estado',
                'Almacen origen', 'Almacen destino', 'Items', 'Diferencias',
                'Motivo', 'Referencia', 'Procesado', 'Preparado', 'Despachado',
                'Recibido', 'Cancelado',
            ],
            'rows' => $rows,
        ];
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
