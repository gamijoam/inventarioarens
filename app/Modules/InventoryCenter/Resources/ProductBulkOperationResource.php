<?php

namespace App\Modules\InventoryCenter\Resources;

use App\Modules\InventoryCenter\Models\ProductBulkOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductBulkOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductBulkOperation $operation */
        $operation = $this->resource;

        return [
            'id' => $operation->id,
            'action' => $operation->action,
            'status' => $operation->status,
            'requested_count' => $operation->requested_count,
            'processed_count' => $operation->processed_count,
            'updated_count' => $operation->updated_count,
            'skipped_count' => $operation->skipped_count,
            'progress_percent' => $operation->requested_count > 0
                ? min(100, (int) round(($operation->processed_count / $operation->requested_count) * 100))
                : ($operation->status === ProductBulkOperation::STATUS_COMPLETED ? 100 : 0),
            'error' => $operation->error,
            'started_at' => $operation->started_at?->toISOString(),
            'completed_at' => $operation->completed_at?->toISOString(),
            'created_at' => $operation->created_at?->toISOString(),
            'updated_at' => $operation->updated_at?->toISOString(),
        ];
    }
}
