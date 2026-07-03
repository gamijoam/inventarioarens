<?php

namespace App\Modules\ProductEntries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'document_number' => $this->document_number,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'created_by_user' => $this->whenLoaded('creator', fn (): array => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'email' => $this->creator->email,
            ]),
            'processed_at' => $this->processed_at?->toISOString(),
            'items' => ProductEntryItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
