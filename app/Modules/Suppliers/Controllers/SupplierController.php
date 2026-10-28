<?php

namespace App\Modules\Suppliers\Controllers;

use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Requests\StoreSupplierRequest;
use App\Modules\Suppliers\Requests\UpdateSupplierRequest;
use App\Modules\Suppliers\Resources\SupplierResource;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Support\Database\SearchNormalization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
            $termExpr = SearchNormalization::term();
            $nameExpr = SearchNormalization::expression('name');
            $docExpr = SearchNormalization::expression("COALESCE(document_number, '')");
            $emailExpr = SearchNormalization::expression("COALESCE(email, '')");
            $phoneExpr = SearchNormalization::expression("COALESCE(phone, '')");
            $like = "%{$search}%";

            $query->where(function ($builder) use ($like, $termExpr, $nameExpr, $docExpr, $emailExpr, $phoneExpr): void {
                $builder
                    ->whereRaw("{$nameExpr} LIKE {$termExpr}", [$like])
                    ->orWhereRaw("{$docExpr} LIKE {$termExpr}", [$like])
                    ->orWhereRaw("{$emailExpr} LIKE {$termExpr}", [$like])
                    ->orWhereRaw("{$phoneExpr} LIKE {$termExpr}", [$like]);
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

    public function store(StoreSupplierRequest $request, SyncCatalogOutboxService $syncCatalog): JsonResponse
    {
        Gate::authorize('create', Supplier::class);

        $supplier = Supplier::create($request->validated())->refresh();
        $syncCatalog->supplierCreated($supplier);

        return SupplierResource::make($supplier)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        Gate::authorize('view', $supplier);

        return SupplierResource::make($supplier);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier, SyncCatalogOutboxService $syncCatalog): SupplierResource
    {
        Gate::authorize('update', $supplier);

        $supplier->update($request->validated());
        $syncCatalog->supplierUpdated($supplier->refresh());

        return SupplierResource::make($supplier->refresh());
    }

    public function destroy(Supplier $supplier, SyncCatalogOutboxService $syncCatalog): Response
    {
        Gate::authorize('delete', $supplier);

        $supplier->update(['is_active' => false]);
        $syncCatalog->supplierUpdated($supplier->refresh());

        return response()->noContent();
    }
}
