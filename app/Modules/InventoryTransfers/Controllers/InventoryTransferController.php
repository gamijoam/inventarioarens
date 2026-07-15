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

        return InventoryTransferResource::collection($query->paginate(25));
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
}
