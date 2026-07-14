<?php

namespace App\Modules\Warehouses\Resources;

use App\Modules\Warehouses\Models\WarehouseLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WarehouseLocation $loc */
        $loc = $this->resource;

        return [
            'id' => $loc->id,
            'warehouse_id' => $loc->warehouse_id,
            'parent_id' => $loc->parent_id,
            'name' => $loc->name,
            'code' => $loc->code,
            'aisle' => $loc->aisle,
            'rack' => $loc->rack,
            'bin' => $loc->bin,
            'level' => $loc->level,
            'capacity' => $loc->capacity,
            'is_active' => (bool) $loc->is_active,
            'full_path' => $loc->fullPath(),
            'parent' => $this->whenLoaded('parent', fn () => $loc->parent ? [
                'id' => $loc->parent->id,
                'name' => $loc->parent->name,
                'code' => $loc->parent->code,
            ] : null),
            'children' => $this->whenLoaded('children', fn () => self::collection($loc->children)),
            'created_at' => $loc->created_at?->toIso8601String(),
            'updated_at' => $loc->updated_at?->toIso8601String(),
        ];
    }
}
