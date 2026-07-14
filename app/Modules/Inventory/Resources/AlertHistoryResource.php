<?php

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'alert_type' => $this->alert_type,
            'severity' => $this->severity,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'title' => $this->title,
            'message' => $this->message,
            'payload' => $this->payload,
            'detected_at' => $this->detected_at?->toIso8601String(),
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
            'dismissed_by' => $this->dismissed_by,
            'is_dismissed' => $this->isDismissed(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
