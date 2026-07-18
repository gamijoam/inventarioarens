<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'printer_station_id' => $this->printer_station_id,
            'print_profile_id' => $this->print_profile_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'pos_order_id' => $this->pos_order_id,
            'sale_id' => $this->sale_id,
            'cash_register_session_id' => $this->cash_register_session_id,
            'requested_by' => $this->requested_by,
            'output' => $this->output,
            'status' => $this->status,
            'is_copy' => (bool) $this->is_copy,
            'attempts' => (int) $this->attempts,
            'digital_pdf_path' => $this->digital_pdf_path,
            'digital_html_path' => $this->digital_html_path,
            'last_error' => $this->last_error,
            'sent_at' => $this->sent_at?->toISOString(),
            'printed_at' => $this->printed_at?->toISOString(),
            'generated_at' => $this->generated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'station' => PrinterStationResource::make($this->whenLoaded('station')),
            'profile' => PrintProfileResource::make($this->whenLoaded('profile')),
            'ticket_html_url' => url("/api/printing/jobs/{$this->id}/ticket.html"),
            'ticket_pdf_url' => url("/api/printing/jobs/{$this->id}/ticket.pdf"),
            'payload_snapshot' => $this->payload_snapshot,
        ];
    }
}
