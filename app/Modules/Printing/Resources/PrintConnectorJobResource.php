<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintConnectorJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'job_uuid' => $this->uuid,
            'output' => $this->output,
            'status' => $this->status,
            'attempts' => (int) $this->attempts,
            'claim_expires_at' => $this->claim_expires_at?->toISOString(),
            'payload_snapshot' => $this->payload_snapshot,
            'ticket_pdf_url' => url("/api/printing/connector/jobs/{$this->uuid}/ticket.pdf"),
            'station' => $this->whenLoaded('station', fn () => [
                'id' => $this->station?->id,
                'name' => $this->station?->name,
                'code' => $this->station?->code,
                'printer_type' => $this->station?->printer_type,
                'printer_name' => $this->station?->printer_name,
                'network_host' => $this->station?->network_host,
                'network_port' => $this->station?->network_port,
            ]),
            'profile' => PrintProfileResource::make($this->whenLoaded('profile')),
        ];
    }
}
