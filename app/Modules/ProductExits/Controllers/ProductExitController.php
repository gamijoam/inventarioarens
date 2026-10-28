<?php

namespace App\Modules\ProductExits\Controllers;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\ProductExits\Models\ProductExit;
use App\Modules\ProductExits\Requests\StoreProductExitRequest;
use App\Modules\ProductExits\Resources\ProductExitResource;
use App\Modules\ProductExits\Services\ProductExitService;
use App\Support\Time\BusinessDateRange;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';
        $termExpr = $isPgsql ? 'unaccent(LOWER(?))' : 'LOWER(?)';
        $snExpr = $isPgsql ? 'unaccent(LOWER(serial_number))' : 'LOWER(serial_number)';
        $matchingUnitIds = $search === ''
            ? collect()
            : ProductUnit::query()
                ->whereRaw("{$snExpr} LIKE {$termExpr}", ["%{$search}%"])
                ->limit(50)
                ->pluck('id');

        $docExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(document_number, \'\')))' : 'LOWER(COALESCE(document_number, \'\'))';
        $reasonExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(reason, \'\')))' : 'LOWER(COALESCE(reason, \'\'))';
        $refExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(reference, \'\')))' : 'LOWER(COALESCE(reference, \'\'))';
        $notesExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(notes, \'\')))' : 'LOWER(COALESCE(notes, \'\'))';
        $pNameExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(name, \'\')))' : 'LOWER(COALESCE(name, \'\'))';
        $pSkuExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(sku, \'\')))' : 'LOWER(COALESCE(sku, \'\'))';
        $like = "%{$search}%";

        $exits = ProductExit::query()
            ->with(['creator', 'items.product', 'items.warehouse'])
            ->when($search !== '', function ($query) use ($like, $termExpr, $docExpr, $reasonExpr, $refExpr, $notesExpr, $pNameExpr, $pSkuExpr, $matchingUnitIds): void {
                $query->where(function ($query) use ($like, $termExpr, $docExpr, $reasonExpr, $refExpr, $notesExpr, $pNameExpr, $pSkuExpr, $matchingUnitIds): void {
                    $query
                        ->whereRaw("{$docExpr} LIKE {$termExpr}", [$like])
                        ->orWhereRaw("{$reasonExpr} LIKE {$termExpr}", [$like])
                        ->orWhereRaw("{$refExpr} LIKE {$termExpr}", [$like])
                        ->orWhereRaw("{$notesExpr} LIKE {$termExpr}", [$like])
                        ->orWhereHas('items.product', function ($query) use ($like, $termExpr, $pNameExpr, $pSkuExpr): void {
                            $query
                                ->whereRaw("{$pNameExpr} LIKE {$termExpr}", [$like])
                                ->orWhereRaw("{$pSkuExpr} LIKE {$termExpr}", [$like]);
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
            ->when($dateFrom, fn ($query) => $query->where('processed_at', '>=', BusinessDateRange::startOfDay($dateFrom)))
            ->when($dateTo, fn ($query) => $query->where('processed_at', '<=', BusinessDateRange::endOfDay($dateTo)))
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
