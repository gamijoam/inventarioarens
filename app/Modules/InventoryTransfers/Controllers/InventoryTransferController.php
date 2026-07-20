<?php

namespace App\Modules\InventoryTransfers\Controllers;

use App\Modules\AccessControl\Services\ScopeResolver;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Requests\AssignDriverRequest;
use App\Modules\InventoryTransfers\Requests\CancelInventoryTransferRequest;
use App\Modules\InventoryTransfers\Requests\CheckChecklistItemRequest;
use App\Modules\InventoryTransfers\Requests\DispatchInventoryTransferRequest;
use App\Modules\InventoryTransfers\Requests\PrepareInventoryTransferRequest;
use App\Modules\InventoryTransfers\Requests\ReceiveInventoryTransferRequest;
use App\Modules\InventoryTransfers\Requests\ResolveInventoryTransferRequest;
use App\Modules\InventoryTransfers\Requests\StoreInventoryTransferRequest;
use App\Modules\InventoryTransfers\Resources\InventoryTransferResource;
use App\Modules\InventoryTransfers\Services\InventoryTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class InventoryTransferController extends Controller
{
    public function __construct(private readonly ScopeResolver $scopes)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', InventoryTransfer::class);

        $query = InventoryTransfer::query()
            ->with(['fromWarehouse', 'toWarehouse', 'guide', 'items.product'])
            ->when($request->query('status'), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->query('validation_mode'), fn ($query, string $mode) => $query->where('validation_mode', $mode))
            ->when($request->query('from_warehouse_id'), fn ($query, string $wid) => $query->where('from_warehouse_id', (int) $wid))
            ->when($request->query('to_warehouse_id'), fn ($query, string $wid) => $query->where('to_warehouse_id', (int) $wid))
            ->when($request->query('date_from'), function ($query, string $date): void {
                $query->where('processed_at', '>=', \Carbon\Carbon::parse($date)->startOfDay());
            })
            ->when($request->query('date_to'), function ($query, string $date): void {
                $query->where('processed_at', '<=', \Carbon\Carbon::parse($date)->endOfDay());
            })
            ->latest('processed_at');

        // Filtrar por scope del user: una transferencia es visible si su
        // from_warehouse_id O to_warehouse_id esta dentro de los warehouses
        // permitidos del user.
        $warehouseIds = $this->scopes->warehouseIdsFor($request->user());
        if ($warehouseIds !== null) {
            $query->where(function ($q) use ($warehouseIds): void {
                $q->whereIn('from_warehouse_id', $warehouseIds)
                    ->orWhereIn('to_warehouse_id', $warehouseIds);
            });
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return InventoryTransferResource::collection($query->paginate($perPage));
    }

    public function store(StoreInventoryTransferRequest $request, InventoryTransferService $service): JsonResponse
    {
        Gate::authorize('create', InventoryTransfer::class);

        return InventoryTransferResource::make(
            $service->create($request->user(), $request->validated())
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function prepare(
        PrepareInventoryTransferRequest $request,
        InventoryTransfer $inventoryTransfer,
        InventoryTransferService $service,
    ): InventoryTransferResource {
        Gate::authorize('prepare', $inventoryTransfer);

        return InventoryTransferResource::make(
            $service->prepare($request->user(), $inventoryTransfer, $request->validated())
        );
    }

    public function dispatch(
        DispatchInventoryTransferRequest $request,
        InventoryTransfer $inventoryTransfer,
        InventoryTransferService $service,
    ): InventoryTransferResource {
        Gate::authorize('dispatch', $inventoryTransfer);

        return InventoryTransferResource::make(
            $service->dispatch($request->user(), $inventoryTransfer, $request->validated())
        );
    }

    public function receive(
        ReceiveInventoryTransferRequest $request,
        InventoryTransfer $inventoryTransfer,
        InventoryTransferService $service,
    ): InventoryTransferResource {
        Gate::authorize('receive', $inventoryTransfer);

        return InventoryTransferResource::make(
            $service->receive($request->user(), $inventoryTransfer, $request->validated())
        );
    }

    public function cancel(
        CancelInventoryTransferRequest $request,
        InventoryTransfer $inventoryTransfer,
        InventoryTransferService $service,
    ): InventoryTransferResource {
        Gate::authorize('cancel', $inventoryTransfer);

        return InventoryTransferResource::make(
            $service->cancel($request->user(), $inventoryTransfer, $request->validated())
        );
    }

    public function resolveDifferences(
        ResolveInventoryTransferRequest $request,
        InventoryTransfer $inventoryTransfer,
        InventoryTransferService $service,
    ): InventoryTransferResource {
        Gate::authorize('resolveDifferences', $inventoryTransfer);

        return InventoryTransferResource::make(
            $service->resolveDifferences($request->user(), $inventoryTransfer, $request->validated())
        );
    }

    /**
     * FASE T1: asigna/actualiza el transportista (driver) de un traslado.
     * El transportista NO necesita user en el sistema; solo se registran
     * sus datos + (opcionalmente) la URL de la firma capturada.
     */
    public function assignDriver(
        Request $request,
        InventoryTransfer $inventoryTransfer,
        AssignDriverRequest $assignRequest,
        InventoryTransferService $service,
    ): \App\Modules\InventoryTransfers\Resources\InventoryTransferDriverResource {
        Gate::authorize('assignDriver', $inventoryTransfer);

        $driver = $service->assignDriver(
            $request->user(),
            $inventoryTransfer,
            $assignRequest->validated(),
        );

        return \App\Modules\InventoryTransfers\Resources\InventoryTransferDriverResource::make($driver);
    }

    public function removeDriver(
        Request $request,
        InventoryTransfer $inventoryTransfer,
        InventoryTransferService $service,
    ): \Illuminate\Http\Response {
        Gate::authorize('assignDriver', $inventoryTransfer);
        $service->removeDriver($request->user(), $inventoryTransfer);

        return response()->noContent();
    }

    /**
     * FASE T1: devuelve el checklist (preparacion o recepcion) del traslado
     * con cada item y su progreso (% checked vs expected).
     */
    public function showChecklist(
        Request $request,
        InventoryTransfer $inventoryTransfer,
        string $stage,
    ): \Illuminate\Http\JsonResponse {
        Gate::authorize('view', $inventoryTransfer);

        if (! in_array($stage, ['preparation', 'reception'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'stage' => 'El stage debe ser preparation o reception.',
            ]);
        }

        $payload = app(InventoryTransferService::class)->checklistFor(
            $inventoryTransfer->refresh()->load(['guide.checklists.items', 'items.product']),
            $stage,
        );

        return response()->json(['data' => $payload]);
    }

    /**
     * FASE T1: marca 1 item del checklist como checked (transportista o
     * receptor confirma que ese item esta listo). Endpoint independiente
     * para soportar la UI de "checklist interactivo" donde el user
     * clickea cada item uno a uno.
     */
    public function checkChecklistItem(
        Request $request,
        InventoryTransfer $inventoryTransfer,
        string $stage,
        int $itemId,
        CheckChecklistItemRequest $checkRequest,
        InventoryTransferService $service,
    ): \Illuminate\Http\JsonResponse {
        Gate::authorize('verify', $inventoryTransfer);

        if (! in_array($stage, ['preparation', 'reception'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'stage' => 'El stage debe ser preparation o reception.',
            ]);
        }

        $service->checkChecklistItem(
            $request->user(),
            $inventoryTransfer,
            $stage,
            $itemId,
            $checkRequest->validated(),
        );

        return response()->json(['data' => $service->checklistFor($inventoryTransfer, $stage)]);
    }

    public function show(InventoryTransfer $inventoryTransfer): InventoryTransferResource
    {
        Gate::authorize('view', $inventoryTransfer);

        return InventoryTransferResource::make(
            $inventoryTransfer->load(['fromWarehouse', 'toWarehouse', 'guide', 'items.product', 'driver'])
        );
    }

    /**
     * FASE T2: devuelve la cronologia del traslado (eventos en orden
     * ascendente por timestamp). Cada evento incluye:
     *   - stage: 'created' | 'prepared' | 'dispatched' | 'received' |
     *            'resolved' | 'cancelled'
     *   - at: timestamp ISO
     *   - by_user: { id, name } (cuando aplique)
     *   - notes: string|null
     *   - differences_count: int (solo en 'received')
     */
    public function timeline(InventoryTransfer $inventoryTransfer): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('view', $inventoryTransfer);

        $inventoryTransfer->loadMissing([
            'creator',
            'preparer',
            'dispatcher',
            'receiver',
            'resolver',
            'canceller',
        ]);

        $events = [];

        if ($inventoryTransfer->requested_at ?? $inventoryTransfer->created_at) {
            $events[] = [
                'stage' => 'created',
                'at' => optional($inventoryTransfer->requested_at ?? $inventoryTransfer->created_at)->toIso8601String(),
                'by_user' => $inventoryTransfer->creator ? [
                    'id' => $inventoryTransfer->creator->id,
                    'name' => $inventoryTransfer->creator->name,
                ] : null,
                'notes' => $inventoryTransfer->reason,
            ];
        }

        if ($inventoryTransfer->prepared_at) {
            $events[] = [
                'stage' => 'prepared',
                'at' => $inventoryTransfer->prepared_at->toIso8601String(),
                'by_user' => $inventoryTransfer->preparer ? [
                    'id' => $inventoryTransfer->preparer->id,
                    'name' => $inventoryTransfer->preparer->name,
                ] : null,
                'notes' => null,
                'has_differences' => $inventoryTransfer->status === InventoryTransfer::STATUS_PREPARED_WITH_DIFFERENCES,
            ];
        }

        if ($inventoryTransfer->dispatched_at) {
            $events[] = [
                'stage' => 'dispatched',
                'at' => $inventoryTransfer->dispatched_at->toIso8601String(),
                'by_user' => $inventoryTransfer->dispatcher ? [
                    'id' => $inventoryTransfer->dispatcher->id,
                    'name' => $inventoryTransfer->dispatcher->name,
                ] : null,
                'notes' => null,
            ];
        }

        if ($inventoryTransfer->received_at) {
            $differencesCount = $inventoryTransfer->items()
                ->where('difference_quantity', '>', 0)
                ->count();
            $events[] = [
                'stage' => 'received',
                'at' => $inventoryTransfer->received_at->toIso8601String(),
                'by_user' => $inventoryTransfer->receiver ? [
                    'id' => $inventoryTransfer->receiver->id,
                    'name' => $inventoryTransfer->receiver->name,
                ] : null,
                'notes' => null,
                'differences_count' => (int) $differencesCount,
            ];
        }

        if ($inventoryTransfer->resolved_at) {
            $events[] = [
                'stage' => 'resolved',
                'at' => $inventoryTransfer->resolved_at->toIso8601String(),
                'by_user' => $inventoryTransfer->resolver ? [
                    'id' => $inventoryTransfer->resolver->id,
                    'name' => $inventoryTransfer->resolver->name,
                ] : null,
                'notes' => $inventoryTransfer->resolution_notes,
                'resolution_status' => $inventoryTransfer->resolution_status,
            ];
        }

        if ($inventoryTransfer->cancelled_at) {
            $events[] = [
                'stage' => 'cancelled',
                'at' => $inventoryTransfer->cancelled_at->toIso8601String(),
                'by_user' => $inventoryTransfer->canceller ? [
                    'id' => $inventoryTransfer->canceller->id,
                    'name' => $inventoryTransfer->canceller->name,
                ] : null,
                'notes' => $inventoryTransfer->notes,
            ];
        }

        usort($events, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return response()->json(['data' => $events]);
    }
}
