<?php

namespace App\Modules\Inventory\Resources;

use App\Modules\Inventory\Models\StockCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StockCount $count */
        $count = $this->resource;
        $items = $count->relationLoaded('items') ? $count->items : collect();

        $stats = [
            'total_items' => $items->count(),
            'pending_items' => $items->where('status', 'pending')->count(),
            'counted_items' => $items->where('status', 'counted')->count(),
            'adjusted_items' => $items->where('status', 'adjusted')->count(),
            'with_variance' => $items->filter(fn ($i) => $i->variance !== null && abs((float) $i->variance) >= 0.0001)->count(),
        ];

        return [
            'id' => $count->id,
            'warehouse_id' => $count->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $count->warehouse ? [
                'id' => $count->warehouse->id,
                'name' => $count->warehouse->name,
                'code' => $count->warehouse->code,
            ] : null),
            'code' => $count->code,
            'name' => $count->name,
            'status' => $count->status,
            'count_type' => $count->count_type,
            'scheduled_at' => $count->scheduled_at?->toDateString(),
            'started_at' => $count->started_at?->toIso8601String(),
            'completed_at' => $count->completed_at?->toIso8601String(),
            'created_by' => $count->created_by,
            'approved_by' => $count->approved_by,
            'notes' => $count->notes,
            'stats' => $stats,
            'items' => $this->whenLoaded('items', fn () => StockCountItemResource::collection($items)),
            'created_at' => $count->created_at?->toIso8601String(),
            'updated_at' => $count->updated_at?->toIso8601String(),
        ];
    }
}
