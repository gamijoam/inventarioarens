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
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class ProductExitController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ProductExit::class);

        $search = trim((string) $request->query('search', ''));
        $warehouseId = $request->query('warehouse_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $limit = min(max((int) $request->query('limit', 25), 1), 100);
        $normalizedSearch = mb_strtolower($search);
        $matchingUnitIds = $search === ''
            ? collect()
            : ProductUnit::query()
                ->whereRaw('LOWER(serial_number) LIKE ?', ["%{$normalizedSearch}%"])
                ->limit(50)
                ->pluck('id');

        $exits = ProductExit::query()
            ->with(['creator', 'items.product', 'items.warehouse'])
            ->when($search !== '', function ($query) use ($normalizedSearch, $matchingUnitIds): void {
                $query->where(function ($query) use ($normalizedSearch, $matchingUnitIds): void {
                    $query
                        ->whereRaw('LOWER(document_number) LIKE ?', ["%{$normalizedSearch}%"])
                        ->orWhereRaw('LOWER(reason) LIKE ?', ["%{$normalizedSearch}%"])
                        ->orWhereRaw('LOWER(COALESCE(reference, \'\')) LIKE ?', ["%{$normalizedSearch}%"])
                        ->orWhereRaw('LOWER(COALESCE(notes, \'\')) LIKE ?', ["%{$normalizedSearch}%"])
                        ->orWhereHas('items.product', function ($query) use ($normalizedSearch): void {
                            $query
                                ->whereRaw('LOWER(name) LIKE ?', ["%{$normalizedSearch}%"])
                                ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$normalizedSearch}%"]);
                        });

                    if ($matchingUnitIds->isNotEmpty()) {
                        $query->orWhereHas('items', function ($query) use ($matchingUnitIds): void {
                            $query->where(function ($query) use ($matchingUnitIds): void {
                                $matchingUnitIds->each(function ($unitId) use ($query): void {
                                    $query->orWhereRaw('CAST(product_unit_ids AS TEXT) LIKE ?', ['%'.$unitId.'%']);
                                });
                            });
                        });
                    }
                });
            })
            ->when($warehouseId, function ($query) use ($warehouseId): void {
                $query->whereHas('items', fn ($query) => $query->where('warehouse_id', $warehouseId));
            })
            ->when($dateFrom, fn ($query) => $query->whereDate('processed_at', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('processed_at', '<=', $dateTo))
            ->latest('processed_at')
            ->paginate($limit);

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
