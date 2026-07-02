<?php

namespace App\Modules\Sales\Controllers;

use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Requests\StoreSaleRequest;
use App\Modules\Sales\Resources\SaleResource;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class SaleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Sale::class);

        return SaleResource::collection(
            Sale::query()
                ->with(['customer', 'items.product', 'items.warehouse'])
                ->latest()
                ->paginate(25)
        );
    }

    public function store(StoreSaleRequest $request, SaleService $sales): JsonResponse
    {
        Gate::authorize('create', Sale::class);

        $sale = $sales->createDraft(
            user: $request->user(),
            items: $request->validated('items'),
            customerId: $request->validated('customer_id')
        );

        return SaleResource::make($sale)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Sale $sale): SaleResource
    {
        Gate::authorize('view', $sale);

        return SaleResource::make($sale->load(['customer', 'items.product', 'items.warehouse', 'items.stockMovement']));
    }

    public function confirm(Sale $sale, SaleService $sales): SaleResource
    {
        Gate::authorize('confirm', $sale);

        return SaleResource::make($sales->confirm($sale, request()->user()));
    }

    public function cancel(Sale $sale, SaleService $sales): SaleResource
    {
        Gate::authorize('cancel', $sale);

        return SaleResource::make($sales->cancelDraft($sale));
    }
}
