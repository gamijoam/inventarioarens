<?php

namespace App\Modules\Products\Resources;

use App\Modules\PaymentMethods\Resources\PaymentMethodResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'markup_percentage' => $this->markup_percentage !== null ? (float) $this->markup_percentage : null,
            'payment_exchange_rate_type_id' => $this->payment_exchange_rate_type_id,
            'payment_exchange_rate_type' => $this->whenLoaded('paymentExchangeRateType', fn () => $this->paymentExchangeRateType ? [
                'id' => $this->paymentExchangeRateType->id,
                'code' => $this->paymentExchangeRateType->code,
                'name' => $this->paymentExchangeRateType->name,
                'is_active' => (bool) $this->paymentExchangeRateType->is_active,
            ] : null),
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'payment_method_ids' => $this->whenLoaded(
                'paymentMethods',
                fn () => $this->paymentMethods->pluck('id')->values(),
                []
            ),
            'payment_methods' => PaymentMethodResource::collection($this->whenLoaded('paymentMethods')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
