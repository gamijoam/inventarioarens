<?php

namespace App\Modules\InventoryTransferRequests\Controllers;

use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Modules\InventoryTransferRequests\Requests\AcceptInventoryTransferRequestRequest;
use App\Modules\InventoryTransferRequests\Requests\GuideItemsRequest;
use App\Modules\InventoryTransferRequests\Requests\RejectInventoryTransferRequestRequest;
use App\Modules\InventoryTransferRequests\Requests\StoreInventoryTransferRequestRequest;
use App\Modules\InventoryTransferRequests\Resources\InventoryTransferRequestResource;
use App\Modules\InventoryTransferRequests\Services\InventoryTransferRequestService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class InventoryTransferRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', InventoryTransferRequest::class);

        $tenantId = app(TenantManager::class)->require()->id;

        return InventoryTransferRequestResource::collection(
            InventoryTransferRequest::query()
                ->with([
                    'originTenant',
                    'destinationTenant',
                    'senderTenant',
                    'receiverTenant',
                    'fromWarehouse',
                    'destinationWarehouse',
                    'senderWarehouse',
                    'receiverWarehouse',
                    'items',
                    'items.originProduct',
                    'items.destinationProduct',
                    'guide.items',
                ])
                ->where(function ($query) use ($tenantId): void {
                    $query
                        ->where('sender_tenant_id', $tenantId)
                        ->orWhere('receiver_tenant_id', $tenantId)
                        ->orWhere('origin_tenant_id', $tenantId)
                        ->orWhere('destination_tenant_id', $tenantId);
                })
                ->when($request->string('status')->value() && $request->string('status')->value() !== 'all', fn ($query) => $query->where('status', $request->string('status')->value()))
                ->when($request->string('flow_type')->value(), fn ($query) => $query->where('flow_type', $request->string('flow_type')->value()))
                ->when($request->string('direction')->value() === 'outbound', fn ($query) => $query->where('sender_tenant_id', $tenantId))
                ->when($request->string('direction')->value() === 'inbound', fn ($query) => $query->where('receiver_tenant_id', $tenantId))
                ->latest('requested_at')
                ->paginate(25)
        );
    }

    public function store(
        StoreInventoryTransferRequestRequest $request,
        InventoryTransferRequestService $service,
    ): JsonResponse {
        Gate::authorize(
            ($request->input('flow_type') ?? InventoryTransferRequest::FLOW_STOCK_REQUEST) === InventoryTransferRequest::FLOW_SHIPMENT_OFFER ? 'offer' : 'create',
            InventoryTransferRequest::class,
        );

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
                'senderTenant',
                'receiverTenant',
                'fromWarehouse',
                'destinationWarehouse',
                'senderWarehouse',
                'receiverWarehouse',
                'items.originProduct',
                'items.destinationProduct',
                'guide.items',
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

    public function prepare(GuideItemsRequest $request, InventoryTransferRequest $inventoryTransferRequest, InventoryTransferRequestService $service): InventoryTransferRequestResource
    {
        Gate::authorize('prepare', $inventoryTransferRequest);

        return InventoryTransferRequestResource::make($service->prepare($inventoryTransferRequest, $request->user(), $request->validated()));
    }

    public function dispatch(InventoryTransferRequest $inventoryTransferRequest, InventoryTransferRequestService $service): InventoryTransferRequestResource
    {
        Gate::authorize('dispatch', $inventoryTransferRequest);

        return InventoryTransferRequestResource::make($service->dispatch($inventoryTransferRequest, request()->user()));
    }

    public function deliver(InventoryTransferRequest $inventoryTransferRequest, InventoryTransferRequestService $service): InventoryTransferRequestResource
    {
        Gate::authorize('deliver', $inventoryTransferRequest);

        return InventoryTransferRequestResource::make($service->deliver($inventoryTransferRequest, request()->user()));
    }

    public function receive(GuideItemsRequest $request, InventoryTransferRequest $inventoryTransferRequest, InventoryTransferRequestService $service): InventoryTransferRequestResource
    {
        Gate::authorize('receive', $inventoryTransferRequest);

        return InventoryTransferRequestResource::make($service->receive($inventoryTransferRequest, $request->user(), $request->validated()));
    }
}
