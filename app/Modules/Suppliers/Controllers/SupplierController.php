<?php

namespace App\Modules\Suppliers\Controllers;

use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Requests\StoreSupplierRequest;
use App\Modules\Suppliers\Requests\UpdateSupplierRequest;
use App\Modules\Suppliers\Resources\SupplierResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class SupplierController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Supplier::class);

        return SupplierResource::collection(
            Supplier::query()
                ->orderBy('name')
                ->paginate(25)
        );
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        Gate::authorize('create', Supplier::class);

        $supplier = Supplier::create($request->validated())->refresh();

        return SupplierResource::make($supplier)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        Gate::authorize('view', $supplier);

        return SupplierResource::make($supplier);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        Gate::authorize('update', $supplier);

        $supplier->update($request->validated());

        return SupplierResource::make($supplier->refresh());
    }

    public function destroy(Supplier $supplier): Response
    {
        Gate::authorize('delete', $supplier);

        $supplier->update(['is_active' => false]);

        return response()->noContent();
    }
}
