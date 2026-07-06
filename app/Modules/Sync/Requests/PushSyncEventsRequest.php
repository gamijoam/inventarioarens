<?php

namespace App\Modules\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PushSyncEventsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'origin_node_code' => ['nullable', 'string', 'max:80'],
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.event_uuid' => ['required', 'uuid'],
            'events.*.event_type' => ['required', 'string', 'max:160'],
            'events.*.aggregate_type' => ['required', 'string', 'max:160'],
            'events.*.aggregate_id' => ['nullable', 'integer', 'min:1'],
            'events.*.payload' => ['nullable', 'array'],
            'events.*.occurred_at' => ['nullable', 'date'],
        ];
    }
}
