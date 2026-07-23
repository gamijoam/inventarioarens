<?php

namespace App\Modules\DataImport\Resources;

use App\Modules\DataImport\Models\DataImportEntity;
use Illuminate\Http\Resources\Json\JsonResource;

class DataImportEntityResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var DataImportEntity $entity */
        $entity = $this->resource;

        return [
            'id' => $entity->id,
            'data_import_id' => $entity->data_import_id,
            'entity' => $entity->entity,
            'status' => $entity->status,
            'total_rows' => (int) ($entity->total_rows ?? 0),
            'succeeded_rows' => (int) ($entity->succeeded_rows ?? 0),
            'skipped_rows' => (int) ($entity->skipped_rows ?? 0),
            'failed_rows' => (int) ($entity->failed_rows ?? 0),
            'error_summary' => $entity->error_summary,
            'started_at' => optional($entity->started_at)?->toIso8601String(),
            'finished_at' => optional($entity->finished_at)?->toIso8601String(),
            'created_at' => optional($entity->created_at)?->toIso8601String(),
        ];
    }
}
