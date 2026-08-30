<?php

namespace App\Modules\Fiscal\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiscalDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'sale_id' => $this->sale_id,
            'document_type' => $this->document_type,
            'document_mode' => $this->document_mode,
            'status' => $this->status,
            'officially_issued' => false,
            'company_snapshot' => $this->company_snapshot,
            'branch_snapshot' => $this->branch_snapshot,
            'customer_snapshot' => $this->customer_snapshot,
            'totals_snapshot' => $this->totals_snapshot,
            'snapshot_at' => $this->snapshot_at?->toISOString(),
            'items' => FiscalDocumentItemResource::collection($this->whenLoaded('items')),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
