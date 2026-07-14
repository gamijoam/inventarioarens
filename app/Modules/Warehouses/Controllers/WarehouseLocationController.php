<?php

namespace App\Modules\Warehouses\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warehouses\Models\WarehouseLocation;
use App\Modules\Warehouses\Requests\StoreWarehouseLocationRequest;
use App\Modules\Warehouses\Requests\UpdateWarehouseLocationRequest;
use App\Modules\Warehouses\Resources\WarehouseLocationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class WarehouseLocationController extends Controller
{
    public function index(Request $request, Warehouse $warehouse): AnonymousResourceCollection
    {
        Gate::authorize('view', $warehouse);

        $query = WarehouseLocation::query()
            ->where('warehouse_id', $warehouse->id)
            ->with(['parent', 'children'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.strtolower((string) $request->input('search')).'%';
                $q->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->when($request->filled('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->boolean('roots_only'), fn ($q) => $q->whereNull('parent_id'))
            ->orderBy('aisle')
            ->orderBy('rack')
            ->orderBy('bin')
            ->orderBy('name');

        return WarehouseLocationResource::collection($query->paginate(50));
    }

    public function store(StoreWarehouseLocationRequest $request, Warehouse $warehouse): JsonResponse
    {
        Gate::authorize('update', $warehouse);

        $data = $request->validated();
        $data['warehouse_id'] = $warehouse->id;

        $location = WarehouseLocation::create($data)->refresh();

        return WarehouseLocationResource::make($location)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Warehouse $warehouse, WarehouseLocation $location): WarehouseLocationResource
    {
        Gate::authorize('view', $warehouse);
        $this->ensureBelongsToWarehouse($warehouse, $location);

        return WarehouseLocationResource::make($location->load(['parent', 'children']));
    }

    public function update(UpdateWarehouseLocationRequest $request, Warehouse $warehouse, WarehouseLocation $location): WarehouseLocationResource
    {
        Gate::authorize('update', $warehouse);
        $this->ensureBelongsToWarehouse($warehouse, $location);

        $location->fill($request->validated())->save();

        return WarehouseLocationResource::make($location->refresh()->load(['parent', 'children']));
    }

    public function destroy(Warehouse $warehouse, WarehouseLocation $location): Response
    {
        Gate::authorize('update', $warehouse);
        $this->ensureBelongsToWarehouse($warehouse, $location);

        $location->delete();

        return response()->noContent();
    }

    private function ensureBelongsToWarehouse(Warehouse $warehouse, WarehouseLocation $location): void
    {
        if ((int) $location->warehouse_id !== (int) $warehouse->id) {
            abort(404, 'Location does not belong to this warehouse.');
        }
    }
}
