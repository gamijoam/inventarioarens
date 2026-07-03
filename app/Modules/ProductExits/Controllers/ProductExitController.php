<?php

namespace App\Modules\ProductExits\Controllers;

use App\Modules\ProductExits\Models\ProductExit;
use App\Modules\ProductExits\Requests\StoreProductExitRequest;
use App\Modules\ProductExits\Resources\ProductExitResource;
use App\Modules\ProductExits\Services\ProductExitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class ProductExitController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ProductExit::class);

        return ProductExitResource::collection(
            ProductExit::query()
                ->with('items')
                ->latest('processed_at')
                ->paginate(25)
        );
    }

    public function store(StoreProductExitRequest $request, ProductExitService $service): JsonResponse
    {
        Gate::authorize('create', ProductExit::class);

        return ProductExitResource::make(
            $service->create($request->user(), $request->validated())
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ProductExit $productExit): ProductExitResource
    {
        Gate::authorize('view', $productExit);

        return ProductExitResource::make($productExit->load(['items.product', 'items.warehouse']));
    }
}
