<?php

namespace App\Modules\Purchases\Controllers;

use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Requests\StorePurchaseOrderRequest;
use App\Modules\Purchases\Resources\PurchaseOrderResource;
use App\Modules\Purchases\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class PurchaseOrderController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        return PurchaseOrderResource::collection(
            PurchaseOrder::query()
                ->with('supplier')
                ->latest()
                ->paginate(25)
        );
    }

    public function store(StorePurchaseOrderRequest $request, PurchaseOrderService $purchases): JsonResponse
    {
        Gate::authorize('create', PurchaseOrder::class);

        $purchaseOrder = $purchases->createDraft($request->user(), $request->validated());

        return PurchaseOrderResource::make($purchaseOrder)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        Gate::authorize('view', $purchaseOrder);

        return PurchaseOrderResource::make(
            $purchaseOrder->load(['supplier', 'items.product', 'items.warehouse', 'items.stockMovement'])
        );
    }

    public function receive(PurchaseOrder $purchaseOrder, PurchaseOrderService $purchases): PurchaseOrderResource
    {
        Gate::authorize('receive', $purchaseOrder);

        return PurchaseOrderResource::make($purchases->receive($purchaseOrder, request()->user()));
    }

    public function cancel(PurchaseOrder $purchaseOrder, PurchaseOrderService $purchases): PurchaseOrderResource
    {
        Gate::authorize('cancel', $purchaseOrder);

        return PurchaseOrderResource::make($purchases->cancelDraft($purchaseOrder));
    }
}
