<?php

namespace App\Modules\InventoryTransfers\Controllers;

use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Requests\CancelInventoryTransferRequest;
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
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', InventoryTransfer::class);

        return InventoryTransferResource::collection(
            InventoryTransfer::query()
                ->with(['fromWarehouse', 'toWarehouse', 'guide', 'items.product'])
                ->when($request->query('status'), fn ($query, string $status) => $query->where('status', $status))
                ->when($request->query('validation_mode'), fn ($query, string $mode) => $query->where('validation_mode', $mode))
                ->latest('processed_at')
                ->paginate(25)
        );
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

    public function show(InventoryTransfer $inventoryTransfer): InventoryTransferResource
    {
        Gate::authorize('view', $inventoryTransfer);

        return InventoryTransferResource::make(
            $inventoryTransfer->load(['fromWarehouse', 'toWarehouse', 'guide', 'items.product'])
        );
    }
}
