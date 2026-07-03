<?php

namespace App\Modules\InventoryTransferRequests\Controllers;

use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Modules\InventoryTransferRequests\Requests\AcceptInventoryTransferRequestRequest;
use App\Modules\InventoryTransferRequests\Requests\RejectInventoryTransferRequestRequest;
use App\Modules\InventoryTransferRequests\Requests\StoreInventoryTransferRequestRequest;
use App\Modules\InventoryTransferRequests\Resources\InventoryTransferRequestResource;
use App\Modules\InventoryTransferRequests\Services\InventoryTransferRequestService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class InventoryTransferRequestController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', InventoryTransferRequest::class);

        $tenantId = app(TenantManager::class)->require()->id;

        return InventoryTransferRequestResource::collection(
            InventoryTransferRequest::query()
                ->with(['originTenant', 'destinationTenant', 'fromWarehouse', 'destinationWarehouse', 'items'])
                ->where(function ($query) use ($tenantId): void {
                    $query
                        ->where('origin_tenant_id', $tenantId)
                        ->orWhere('destination_tenant_id', $tenantId);
                })
                ->latest('requested_at')
                ->paginate(25)
        );
    }

    public function store(
        StoreInventoryTransferRequestRequest $request,
        InventoryTransferRequestService $service,
    ): JsonResponse {
        Gate::authorize('create', InventoryTransferRequest::class);

        return InventoryTransferRequestResource::make(
            $service->create($request->user(), $request->validated())
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(InventoryTransferRequest $inventoryTransferRequest): InventoryTransferRequestResource
    {
        Gate::authorize('view', $inventoryTransferRequest);

        return InventoryTransferRequestResource::make(
            $inventoryTransferRequest->load([
                'originTenant',
                'destinationTenant',
                'fromWarehouse',
                'destinationWarehouse',
                'items.originProduct',
                'items.destinationProduct',
            ])
        );
    }

    public function accept(
        AcceptInventoryTransferRequestRequest $request,
        InventoryTransferRequest $inventoryTransferRequest,
        InventoryTransferRequestService $service,
    ): InventoryTransferRequestResource {
        Gate::authorize('accept', $inventoryTransferRequest);

        return InventoryTransferRequestResource::make(
            $service->accept($inventoryTransferRequest, $request->user(), $request->validated())
        );
    }

    public function reject(
        RejectInventoryTransferRequestRequest $request,
        InventoryTransferRequest $inventoryTransferRequest,
        InventoryTransferRequestService $service,
    ): InventoryTransferRequestResource {
        Gate::authorize('reject', $inventoryTransferRequest);

        return InventoryTransferRequestResource::make(
            $service->reject($inventoryTransferRequest, $request->user(), $request->validated())
        );
    }

    public function cancel(
        InventoryTransferRequest $inventoryTransferRequest,
        InventoryTransferRequestService $service,
    ): InventoryTransferRequestResource {
        Gate::authorize('cancel', $inventoryTransferRequest);

        return InventoryTransferRequestResource::make(
            $service->cancel($inventoryTransferRequest, request()->user())
        );
    }
}
