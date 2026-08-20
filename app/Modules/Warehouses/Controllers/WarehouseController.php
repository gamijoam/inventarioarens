<?php

namespace App\Modules\Warehouses\Controllers;

use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warehouses\Requests\StoreWarehouseRequest;
use App\Modules\Warehouses\Requests\UpdateWarehouseRequest;
use App\Modules\Warehouses\Resources\WarehouseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WarehouseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Warehouse::class);

        $perPage = $this->resolvePerPage($request);

        return WarehouseResource::collection(
            Warehouse::query()
                ->with('branch')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->paginate($perPage)
        );
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        Gate::authorize('create', Warehouse::class);

        $warehouse = DB::transaction(function () use ($request): Warehouse {
            $data = $request->validated();

            if (($data['is_default'] ?? false) === true) {
                Warehouse::query()->update(['is_default' => false]);
            }

            return Warehouse::create($data)->refresh();
        });

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

        DB::transaction(function () use ($request, $warehouse): void {
            $data = $request->validated();

            if (($data['is_default'] ?? false) === true) {
                Warehouse::query()
                    ->whereKeyNot($warehouse->id)
                    ->update(['is_default' => false]);
            }

            $warehouse->update($data);
        });

        return WarehouseResource::make($warehouse->refresh()->load('branch'));
    }

    public function destroy(Warehouse $warehouse): Response
    {
        Gate::authorize('delete', $warehouse);

        $warehouse->update(['status' => Warehouse::STATUS_INACTIVE]);

        return response()->noContent();
    }

    private function resolvePerPage(Request $request): int
    {
        $raw = $request->query('per_page', $request->query('limit', 25));

        return max(1, min(100, (int) $raw));
    }
}
