<?php

namespace App\Modules\ProductExits\Controllers;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\ProductExits\Models\ProductExit;
use App\Modules\ProductExits\Requests\StoreProductExitRequest;
use App\Modules\ProductExits\Resources\ProductExitResource;
use App\Modules\ProductExits\Services\ProductExitService;
use Illuminate\Database\Eloquent\Collection;
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

        $exits = ProductExit::query()
            ->with(['creator', 'items.product', 'items.warehouse'])
            ->latest('processed_at')
            ->paginate(25);

        $this->attachSerialUnits($exits->getCollection());

        return ProductExitResource::collection(
            $exits
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

        $productExit->load(['creator', 'items.product', 'items.warehouse']);
        $this->attachSerialUnits(new Collection([$productExit]));

        return ProductExitResource::make($productExit);
    }

    private function attachSerialUnits(Collection $exits): void
    {
        $items = $exits->flatMap(fn (ProductExit $exit) => $exit->items);
        $unitIds = $items
            ->flatMap(fn ($item): array => $item->product_unit_ids ?? [])
            ->unique()
            ->values();

        if ($unitIds->isEmpty()) {
            return;
        }

        $units = ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->get()
            ->keyBy('id');

        $items->each(function ($item) use ($units): void {
            $item->setAttribute(
                'serial_units',
                collect($item->product_unit_ids ?? [])
                    ->map(fn ($unitId) => $units->get($unitId))
                    ->filter()
                    ->values()
            );
        });
    }
}
