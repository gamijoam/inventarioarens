<?php

namespace App\Modules\InventoryTransferRequests\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntercompanyNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventory_transfer_request_id' => $this->inventory_transfer_request_id,
            'event_type' => $this->event_type,
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->action_url,
            'is_read' => (bool) ($this->is_read ?? false),
            'occurred_at' => $this->occurred_at?->toISOString(),
        ];
    }
}
