<?php

namespace App\Modules\ProductEntries\Controllers;

use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\ProductEntries\Requests\StoreProductEntryRequest;
use App\Modules\ProductEntries\Resources\ProductEntryResource;
use App\Modules\ProductEntries\Services\ProductEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class ProductEntryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ProductEntry::class);

        return ProductEntryResource::collection(
            ProductEntry::query()
                ->with(['creator', 'items.product', 'items.warehouse'])
                ->latest('processed_at')
                ->paginate(25)
        );
    }

    public function store(StoreProductEntryRequest $request, ProductEntryService $service): JsonResponse
    {
        Gate::authorize('create', ProductEntry::class);

        return ProductEntryResource::make(
            $service->create($request->user(), $request->validated())
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ProductEntry $productEntry): ProductEntryResource
    {
        Gate::authorize('view', $productEntry);

        return ProductEntryResource::make($productEntry->load(['creator', 'items.product', 'items.warehouse']));
    }
}
