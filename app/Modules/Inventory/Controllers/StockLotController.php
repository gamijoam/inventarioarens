<?php

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\StockLotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class StockLotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        $filters = $request->validate([
            'status' => ['nullable', 'in:expiring,expired,all'],
            'days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'warehouse_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
        ]);

        $status = $filters['status'] ?? 'expiring';
        $today = now()->startOfDay();
        $limit = $today->copy()->addDays((int) ($filters['days'] ?? 30));

        $lots = StockLot::query()
            ->with(['product:id,name,sku', 'warehouse:id,name,code', 'variant:id,color,sku_variant'])
            ->where('quantity', '>', 0)
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($status === 'expiring', fn ($q) => $q->whereDate('expires_on', '>=', $today)->whereDate('expires_on', '<=', $limit))
            ->when($status === 'expired', fn ($q) => $q->whereDate('expires_on', '<', $today))
            ->orderBy('expires_on')
            ->orderBy('id')
            ->limit(500)
            ->get();

        $rows = $lots->map(fn (StockLot $lot): array => [
            'id' => $lot->id,
            'lot_code' => $lot->lot_code,
            'expires_on' => $lot->expires_on->toDateString(),
            'days_left' => (int) $today->diffInDays($lot->expires_on->copy()->startOfDay(), false),
            'quantity' => (float) $lot->quantity,
            'unit_cost' => $lot->unit_cost === null ? null : (float) $lot->unit_cost,
            'value_base_amount' => round((float) $lot->quantity * (float) ($lot->unit_cost ?? 0), 4),
            'product' => $lot->product ? ['id' => $lot->product->id, 'name' => $lot->product->name, 'sku' => $lot->product->sku] : null,
            'variant' => $lot->variant ? ['id' => $lot->variant->id, 'color' => $lot->variant->color, 'sku_variant' => $lot->variant->sku_variant] : null,
            'warehouse' => $lot->warehouse ? ['id' => $lot->warehouse->id, 'name' => $lot->warehouse->name, 'code' => $lot->warehouse->code] : null,
            'updated_at' => $lot->updated_at?->toISOString(),
        ])->values();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'lots' => $rows->count(),
                'value_base_amount' => round((float) $rows->sum('value_base_amount'), 4),
                'expired_value_base_amount' => round((float) $rows->where('days_left', '<', 0)->sum('value_base_amount'), 4),
            ],
        ]);
    }

    public function writeOff(Request $request, StockLot $stockLot, StockLotService $lots): JsonResponse
    {
        abort_unless($request->user()->can('inventory.adjust'), 403, 'Dar de baja mercancia requiere el permiso de ajuste de inventario.');

        $data = $request->validate([
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = $lots->writeOff($stockLot, $request->user(), isset($data['quantity']) ? (float) $data['quantity'] : null, $data['reason'] ?? null);

        return response()->json(['data' => [
            'lot_id' => $stockLot->id,
            'remaining_quantity' => (float) $stockLot->refresh()->quantity,
            'movement_id' => $movement->id,
            'quantity' => (float) $movement->quantity,
            'unit_cost' => $movement->unit_cost === null ? null : (float) $movement->unit_cost,
        ]]);
    }
}
