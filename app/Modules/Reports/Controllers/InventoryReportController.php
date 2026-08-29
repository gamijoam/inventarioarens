<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Reports\Requests\MovementReportRequest;
use App\Modules\Reports\Requests\StockReportRequest;
use App\Modules\Reports\Resources\MovementReportResource;
use App\Modules\Reports\Resources\StockReportResource;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    public function stock(StockReportRequest $request): AnonymousResourceCollection
    {
        $balances = StockBalance::query()
            ->with(['warehouse', 'product'])
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->when($request->filled('product_variant_id'), fn ($query) => $query->where('product_variant_id', $request->integer('product_variant_id')))
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->paginate($request->integer('per_page', 50));

        return StockReportResource::collection($balances);
    }

    public function lowStock(StockReportRequest $request): AnonymousResourceCollection
    {
        $threshold = (float) $request->input('threshold', 1);

        $balances = StockBalance::query()
            ->with(['warehouse', 'product'])
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->where('quantity_available', '<=', $threshold)
            ->orderBy('quantity_available')
            ->orderBy('product_id')
            ->paginate($request->integer('per_page', 50));

        return StockReportResource::collection($balances);
    }

    public function movements(MovementReportRequest $request): AnonymousResourceCollection
    {
        $movements = StockMovement::query()
            ->with(['warehouse', 'product', 'variant'])
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->when($request->filled('product_variant_id'), function ($query) use ($request): void {
                $value = $request->input('product_variant_id');
                if ($value === 'null' || $value === null || $value === '') {
                    $query->whereNull('product_variant_id');
                } else {
                    $query->where('product_variant_id', (int) $value);
                }
            })
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('date_to')))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return MovementReportResource::collection($movements);
    }

    public function stockByVariant(StockReportRequest $request): JsonResponse
    {
        $paginator = DB::table('stock_balances as sb')
            ->join('product_variants as pv', 'pv.id', '=', 'sb.product_variant_id')
            ->join('products as p', 'p.id', '=', 'sb.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sb.warehouse_id')
            ->where('sb.tenant_id', app(TenantManager::class)->require()->id)
            ->when($request->filled('product_id'), fn ($query) => $query->where('sb.product_id', $request->integer('product_id')))
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('sb.warehouse_id', $request->integer('warehouse_id')))
            ->groupBy('pv.id', 'pv.color', 'pv.color_hex', 'p.id', 'p.name', 'p.sku', 'w.id', 'w.code', 'w.name')
            ->orderBy('p.name')
            ->orderBy('pv.position')
            ->selectRaw('pv.id as variant_id, pv.color as variant_color, pv.color_hex as variant_color_hex, p.id as product_id, p.name as product_name, p.sku as product_sku, w.id as warehouse_id, w.code as warehouse_code, w.name as warehouse_name')
            ->selectRaw('SUM(sb.quantity_available) as quantity_available')
            ->selectRaw('SUM(sb.quantity_reserved) as quantity_reserved')
            ->selectRaw('SUM(sb.quantity_damaged) as quantity_damaged')
            ->paginate($request->integer('per_page', 50));

        $rows = $paginator->getCollection()->map(fn ($row): array => [
            'variant_id' => (int) $row->variant_id,
            'variant_color' => $row->variant_color,
            'variant_color_hex' => $row->variant_color_hex,
            'product_id' => (int) $row->product_id,
            'product_name' => $row->product_name,
            'product_sku' => $row->product_sku,
            'warehouse_id' => $row->warehouse_id !== null ? (int) $row->warehouse_id : null,
            'warehouse_code' => $row->warehouse_code,
            'warehouse_name' => $row->warehouse_name,
            'quantity_available' => (float) $row->quantity_available,
            'quantity_reserved' => (float) $row->quantity_reserved,
            'quantity_damaged' => (float) $row->quantity_damaged,
        ])->values()->all();

        return response()->json([
            'data' => $rows,
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
