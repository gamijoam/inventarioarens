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
            'total_entities' => (int) ($import->total_entities ?? 0),
            'total_rows' => (int) ($import->total_rows ?? 0),
            'processed_rows' => (int) ($import->processed_rows ?? 0),
            'succeeded_rows' => (int) ($import->succeeded_rows ?? 0),
            'skipped_rows' => (int) ($import->skipped_rows ?? 0),
            'failed_rows' => (int) ($import->failed_rows ?? 0),
            'meta' => $import->meta,
            'started_at' => optional($import->started_at)?->toIso8601String(),
            'finished_at' => optional($import->finished_at)?->toIso8601String(),
            'created_at' => optional($import->created_at)?->toIso8601String(),
            'updated_at' => optional($import->updated_at)?->toIso8601String(),
            'entities' => $this->whenLoaded('entities', fn () => DataImportEntityResource::collection($this->resource->entities)),
        ];
    }
}
