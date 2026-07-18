<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrinterStationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'cash_register_id' => $this->cash_register_id,
            'print_profile_id' => $this->print_profile_id,
            'name' => $this->name,
            'code' => $this->code,
            'output_mode' => $this->output_mode,
            'printer_type' => $this->printer_type,
            'printer_name' => $this->printer_name,
            'network_host' => $this->network_host,
            'network_port' => $this->network_port,
            'digital_directory' => $this->digital_directory,
            'save_html_copy' => (bool) $this->save_html_copy,
            'is_active' => (bool) $this->is_active,
            'profile' => PrintProfileResource::make($this->whenLoaded('profile')),
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'cash_register_name' => $this->whenLoaded('cashRegister', fn () => $this->cashRegister?->name),
        ];
    }
}
