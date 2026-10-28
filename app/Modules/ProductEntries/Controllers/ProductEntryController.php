<?php

namespace App\Modules\ProductEntries\Controllers;

use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\ProductEntries\Requests\StoreProductEntryRequest;
use App\Modules\ProductEntries\Resources\ProductEntryResource;
use App\Modules\ProductEntries\Services\ProductEntryService;
use App\Support\Time\BusinessDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
                ->when($search !== '', function ($query) use ($search): void {
                    $isPgsql = DB::connection()->getDriverName() === 'pgsql';
                    $termExpr = $isPgsql ? 'unaccent(LOWER(?))' : 'LOWER(?)';
                    $docExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(document_number, \'\')))' : 'LOWER(COALESCE(document_number, \'\'))';
                    $reasonExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(reason, \'\')))' : 'LOWER(COALESCE(reason, \'\'))';
                    $refExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(reference, \'\')))' : 'LOWER(COALESCE(reference, \'\'))';
                    $notesExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(notes, \'\')))' : 'LOWER(COALESCE(notes, \'\'))';
                    $pNameExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(name, \'\')))' : 'LOWER(COALESCE(name, \'\'))';
                    $pSkuExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(sku, \'\')))' : 'LOWER(COALESCE(sku, \'\'))';
                    $like = "%{$search}%";

                    $query->where(function ($query) use ($like, $termExpr, $docExpr, $reasonExpr, $refExpr, $notesExpr, $pNameExpr, $pSkuExpr): void {
                        $query
                            ->whereRaw("{$docExpr} LIKE {$termExpr}", [$like])
                            ->orWhereRaw("{$reasonExpr} LIKE {$termExpr}", [$like])
                            ->orWhereRaw("{$refExpr} LIKE {$termExpr}", [$like])
                            ->orWhereRaw("{$notesExpr} LIKE {$termExpr}", [$like])
                            ->orWhereHas('items.product', function ($query) use ($like, $termExpr, $pNameExpr, $pSkuExpr): void {
                                $query
                                    ->whereRaw("{$pNameExpr} LIKE {$termExpr}", [$like])
                                    ->orWhereRaw("{$pSkuExpr} LIKE {$termExpr}", [$like]);
                            })
                            ->orWhereHas('items', function ($query) use ($like, $termExpr): void {
                                $query->whereRaw("LOWER(CAST(serial_units AS TEXT)) LIKE {$termExpr}", [$like]);
                            });
                    });
                })
                ->when($warehouseId, function ($query) use ($warehouseId): void {
                    $query->whereHas('items', fn ($query) => $query->where('warehouse_id', $warehouseId));
                })
                ->when($dateFrom, fn ($query) => $query->where('processed_at', '>=', BusinessDateRange::startOfDay($dateFrom)))
                ->when($dateTo, fn ($query) => $query->where('processed_at', '<=', BusinessDateRange::endOfDay($dateTo)))
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
