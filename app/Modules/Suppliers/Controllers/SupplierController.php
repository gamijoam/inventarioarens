<?php

namespace App\Modules\Suppliers\Controllers;

use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Requests\StoreSupplierRequest;
use App\Modules\Suppliers\Requests\UpdateSupplierRequest;
use App\Modules\Suppliers\Resources\SupplierResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class SupplierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Supplier::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'active_status' => ['nullable', 'in:active,inactive,all'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Supplier::query()
            ->orderBy('name')
            ->orderBy('id');

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';

            $query->where(function ($builder) use ($like): void {
                $builder
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(document_number, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$like]);
            });
        }

        $activeStatus = $filters['active_status'] ?? 'all';

        if ($activeStatus !== 'all') {
            $query->where('is_active', $activeStatus === 'active');
        }

        return SupplierResource::collection(
            $query
                ->paginate((int) ($filters['limit'] ?? 25))
                ->appends($request->query())
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
