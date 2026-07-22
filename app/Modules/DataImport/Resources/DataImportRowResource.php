<?php

namespace App\Modules\DataImport\Resources;

use App\Modules\DataImport\Models\DataImportRow;
use Illuminate\Http\Resources\Json\JsonResource;

class DataImportRowResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var DataImportRow $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'row_number' => $row->row_number,
            'status' => $row->status,
            'payload' => $row->payload,
            'errors' => $row->errors,
            'natural_key' => $row->natural_key,
            'resulting_id' => $row->resulting_id,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];
    }
}
