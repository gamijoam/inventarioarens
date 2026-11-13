<?php

namespace App\Modules\Products\Models;

use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'product_variant_id',
    'name',
    'factor',
    'barcode',
    'price_usd',
    'is_active',
])]
class ProductPackaging extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::saved(function (ProductPackaging $packaging): void {
            try {
                app(SyncCatalogOutboxService::class)->productPackagingUpserted($packaging);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:4',
            'price_usd' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Precio unitario en USD que implica el precio propio, o null.
     */
    public function unitPriceUsd(): ?float
    {
        if ($this->price_usd === null || (float) $this->factor <= 0.0) {
            return null;
        }

        return round((float) $this->price_usd / (float) $this->factor, 6);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'name' => $this->name,
            'factor' => (float) $this->factor,
            'barcode' => $this->barcode,
            'price_usd' => $this->price_usd === null ? null : (float) $this->price_usd,
            'unit_price_usd' => $this->unitPriceUsd() === null ? null : round($this->unitPriceUsd(), 4),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
