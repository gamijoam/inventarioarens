<?php

namespace App\Modules\SalesReturns\Controllers;

use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\SalesReturns\Requests\StoreSalesReturnRequest;
use App\Modules\SalesReturns\Resources\SalesReturnResource;
use App\Modules\SalesReturns\Services\SalesReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class SalesReturnController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SalesReturn::class);

        return SalesReturnResource::collection(
            SalesReturn::query()
                ->with('sale.customer')
                ->latest()
                ->paginate(25)
        );
    }

    public function store(StoreSalesReturnRequest $request, SalesReturnService $returns): JsonResponse
    {
        Gate::authorize('create', SalesReturn::class);

        $salesReturn = $returns->create($request->user(), $request->validated());

        return SalesReturnResource::make($salesReturn)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(SalesReturn $salesReturn): SalesReturnResource
    {
        Gate::authorize('view', $salesReturn);

        return SalesReturnResource::make(
            $salesReturn->load(['sale.customer', 'items.product', 'items.warehouse', 'items.stockMovement'])
        );
    }
}
