<?php

namespace App\Modules\Products\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,

            'name' => $this->name,
            'description' => $this->description,
            'long_description' => $this->long_description,

            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'image_url' => $this->image_url,

            'tracking_type' => $this->tracking_type,
            'unit_of_measure' => $this->unit_of_measure,
            'track_stock' => (bool) $this->track_stock,

            'brand_id' => $this->brand_id,
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
            ] : null),

            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'full_path' => $c->parent
                    ? trim($c->parent->name.' / '.$c->name, ' /')
                    : $c->name,
            ])),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'color' => $t->color,
            ])),

            'base_price' => $this->base_price === null ? null : (float) $this->base_price,
            'sale_currency' => $this->sale_currency,
            'sale_exchange_rate_type_id' => $this->sale_exchange_rate_type_id,
            'sale_exchange_rate_type' => $this->whenLoaded('saleExchangeRateType', fn () => $this->saleExchangeRateType ? [
                'id' => $this->saleExchangeRateType->id,
                'code' => $this->saleExchangeRateType->code,
                'name' => $this->saleExchangeRateType->name,
                'is_default' => (bool) $this->saleExchangeRateType->is_default,
                'is_active' => (bool) $this->saleExchangeRateType->is_active,
            ] : null),

            'min_stock' => $this->min_stock === null ? null : (float) $this->min_stock,
            'max_stock' => $this->max_stock === null ? null : (float) $this->max_stock,
            'reorder_quantity' => $this->reorder_quantity === null ? null : (float) $this->reorder_quantity,
            'suggested_purchase' => $this->when(isset($this->suggested_purchase), fn () => (float) $this->suggested_purchase),

            'average_cost' => $this->average_cost === null ? null : (float) $this->average_cost,
            'average_cost_visible' => (bool) ($request->user()?->can('finance.costs.view') ?? false),

            'warranty_policy_id' => $this->warranty_policy_id,
            'warranty_policy' => $this->whenLoaded('warrantyPolicy', fn () => $this->warrantyPolicy ? [
                'id' => $this->warrantyPolicy->id,
                'name' => $this->warrantyPolicy->name,
                'duration_days' => $this->warrantyPolicy->duration_days,
                'coverage_type' => $this->warrantyPolicy->coverage_type,
            ] : null),

            'can_change_tracking_type' => $this->whenCounted('units', fn (): bool => (int) $this->units_count === 0),
            'units_count' => $this->whenCounted('units', fn (): int => (int) $this->units_count),

            // Suma de stock_balances.quantity_available del producto. Si
            // el controller del index paso warehouse_id, este campo se
            // reescribe con la suma SOLO de ese almacen (ver ProductController
            // ::index con withSum(['stockBalances as available_stock' => ...])).
            'available_stock' => (float) ($this->available_stock ?? 0),

            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
