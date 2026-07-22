<?php

namespace App\Modules\DataImport\Resources;

use App\Modules\DataImport\Models\DataImport;
use Illuminate\Http\Resources\Json\JsonResource;

class DataImportResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var DataImport $import */
        $import = $this->resource;

        return [
            'id' => $import->id,
            'tenant_id' => $import->tenant_id,
            'user_id' => $import->user_id,
            'status' => $import->status,
            'total_entities' => $import->total_entities,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'succeeded_rows' => $import->succeeded_rows,
            'skipped_rows' => $import->skipped_rows,
            'failed_rows' => $import->failed_rows,
            'meta' => $import->meta,
            'started_at' => optional($import->started_at)?->toIso8601String(),
            'finished_at' => optional($import->finished_at)?->toIso8601String(),
            'created_at' => optional($import->created_at)?->toIso8601String(),
            'updated_at' => optional($import->updated_at)?->toIso8601String(),
            'entities' => $this->whenLoaded('entities', fn () => DataImportEntityResource::collection($this->resource->entities)),
        ];
    }
}
