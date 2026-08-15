<?php

namespace App\Modules\PaymentMethods\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'code' => $this->code,
            'method' => $this->method,
            'currency_mode' => $this->currency_mode,
            'requires_reference' => (bool) $this->requires_reference,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'report_code' => $this->report_code,
            'report_label' => $this->report_label,
            'report_visible' => (bool) $this->report_visible,
            'report_sort_order' => (int) $this->report_sort_order,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
