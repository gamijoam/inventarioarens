<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Reports\Requests\MovementReportRequest;
use App\Modules\Reports\Requests\StockReportRequest;
use App\Modules\Reports\Resources\MovementReportResource;
use App\Modules\Reports\Resources\StockReportResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryReportController extends Controller
{
    public function stock(StockReportRequest $request): AnonymousResourceCollection
    {
        $balances = StockBalance::query()
            ->with(['warehouse', 'product'])
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->get();

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
            ->get();

        return StockReportResource::collection($balances);
    }

    public function movements(MovementReportRequest $request): AnonymousResourceCollection
    {
        $movements = StockMovement::query()
            ->with(['warehouse', 'product'])
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('date_to')))
            ->latest('id')
            ->get();

        return MovementReportResource::collection($movements);
    }
}
