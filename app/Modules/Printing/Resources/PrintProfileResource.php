<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'paper_width_mm' => $this->paper_width_mm,
            'characters_per_line' => $this->characters_per_line,
            'header_text' => $this->header_text,
            'footer_text' => $this->footer_text,
            'logo_text' => $this->logo_text,
            'show_warranty_summary' => (bool) $this->show_warranty_summary,
            'cut_paper' => (bool) $this->cut_paper,
            'open_cash_drawer' => (bool) $this->open_cash_drawer,
            'copies' => (int) $this->copies,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
