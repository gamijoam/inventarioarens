<?php

namespace App\Modules\PurchaseReturns\Controllers;

use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\PurchaseReturns\Requests\StorePurchaseReturnRequest;
use App\Modules\PurchaseReturns\Resources\PurchaseReturnResource;
use App\Modules\PurchaseReturns\Services\PurchaseReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class PurchaseReturnController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PurchaseReturn::class);

        return PurchaseReturnResource::collection(
            PurchaseReturn::query()
                ->with('purchaseOrder.supplier')
                ->latest()
                ->paginate(25)
        );
    }

    public function store(StorePurchaseReturnRequest $request, PurchaseReturnService $returns): JsonResponse
    {
        Gate::authorize('create', PurchaseReturn::class);

        $purchaseReturn = $returns->create($request->user(), $request->validated());

        return PurchaseReturnResource::make($purchaseReturn)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PurchaseReturn $purchaseReturn): PurchaseReturnResource
    {
        Gate::authorize('view', $purchaseReturn);

        return PurchaseReturnResource::make(
            $purchaseReturn->load(['purchaseOrder.supplier', 'items.product', 'items.warehouse', 'items.stockMovement'])
        );
    }
}
