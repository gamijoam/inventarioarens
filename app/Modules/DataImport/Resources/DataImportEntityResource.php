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
            'total_rows' => $entity->total_rows,
            'succeeded_rows' => $entity->succeeded_rows,
            'skipped_rows' => $entity->skipped_rows,
            'failed_rows' => $entity->failed_rows,
            'error_summary' => $entity->error_summary,
            'started_at' => optional($entity->started_at)?->toIso8601String(),
            'finished_at' => optional($entity->finished_at)?->toIso8601String(),
            'created_at' => optional($entity->created_at)?->toIso8601String(),
        ];
    }
}
