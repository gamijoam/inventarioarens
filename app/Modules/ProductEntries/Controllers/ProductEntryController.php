<?php

namespace App\Modules\ProductEntries\Controllers;

use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\ProductEntries\Requests\StoreProductEntryRequest;
use App\Modules\ProductEntries\Resources\ProductEntryResource;
use App\Modules\ProductEntries\Services\ProductEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class ProductEntryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ProductEntry::class);

        $search = trim((string) $request->query('search', ''));
        $warehouseId = $request->query('warehouse_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $limit = min(max((int) $request->query('limit', 25), 1), 100);
        $normalizedSearch = mb_strtolower($search);

        return ProductEntryResource::collection(
            ProductEntry::query()
                ->with(['creator', 'items.product', 'items.warehouse'])
                ->when($search !== '', function ($query) use ($normalizedSearch): void {
                    $query->where(function ($query) use ($normalizedSearch): void {
                        $query
                            ->whereRaw('LOWER(document_number) LIKE ?', ["%{$normalizedSearch}%"])
                            ->orWhereRaw('LOWER(reason) LIKE ?', ["%{$normalizedSearch}%"])
                            ->orWhereRaw('LOWER(COALESCE(reference, \'\')) LIKE ?', ["%{$normalizedSearch}%"])
                            ->orWhereRaw('LOWER(COALESCE(notes, \'\')) LIKE ?', ["%{$normalizedSearch}%"])
                            ->orWhereHas('items.product', function ($query) use ($normalizedSearch): void {
                                $query
                                    ->whereRaw('LOWER(name) LIKE ?', ["%{$normalizedSearch}%"])
                                    ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$normalizedSearch}%"]);
                            })
                            ->orWhereHas('items', function ($query) use ($normalizedSearch): void {
                                $query->whereRaw('LOWER(CAST(serial_units AS TEXT)) LIKE ?', ["%{$normalizedSearch}%"]);
                            });
                    });
                })
                ->when($warehouseId, function ($query) use ($warehouseId): void {
                    $query->whereHas('items', fn ($query) => $query->where('warehouse_id', $warehouseId));
                })
                ->when($dateFrom, fn ($query) => $query->whereDate('processed_at', '>=', $dateFrom))
                ->when($dateTo, fn ($query) => $query->whereDate('processed_at', '<=', $dateTo))
                ->latest('processed_at')
                ->paginate($limit)
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
