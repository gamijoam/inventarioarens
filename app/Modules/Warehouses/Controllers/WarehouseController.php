<?php

namespace App\Modules\Warehouses\Controllers;

use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warehouses\Requests\StoreWarehouseRequest;
use App\Modules\Warehouses\Requests\UpdateWarehouseRequest;
use App\Modules\Warehouses\Resources\WarehouseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class WarehouseController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Warehouse::class);

        return WarehouseResource::collection(
            Warehouse::query()
                ->with('branch')
                ->orderBy('name')
                ->paginate(25)
        );
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        Gate::authorize('create', Warehouse::class);

        $warehouse = Warehouse::create($request->validated())->refresh();

        return WarehouseResource::make($warehouse->load('branch'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Warehouse $warehouse): WarehouseResource
    {
        Gate::authorize('view', $warehouse);

        return WarehouseResource::make($warehouse->load('branch'));
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        Gate::authorize('update', $warehouse);

        $warehouse->update($request->validated());

        return WarehouseResource::make($warehouse->refresh()->load('branch'));
    }

    public function destroy(Warehouse $warehouse): Response
    {
        Gate::authorize('delete', $warehouse);

        $warehouse->update(['status' => Warehouse::STATUS_INACTIVE]);

        return response()->noContent();
    }
}
