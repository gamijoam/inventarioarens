<?php

namespace App\Modules\Products\Models;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'product_id',
    'color',
    'color_hex',
    'sku_variant',
    'barcode_variant',
    'price_override',
    'is_active',
    'position',
])]
class ProductVariant extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::created(function (ProductVariant $variant): void {
            static::propagateAfterCommit(fn () => static::propagateVariant($variant));
        });

        static::updated(function (ProductVariant $variant): void {
            static::propagateAfterCommit(fn () => static::propagateVariant($variant));
        });

        static::deleted(function (ProductVariant $variant): void {
            static::propagateAfterCommit(fn () => static::propagateVariantDeleted($variant));
        });
    }

    protected static function propagateAfterCommit(callable $runner): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($runner);

            return;
        }

        try {
            $runner();
        } catch (\Throwable $e) {
            logger()->warning('Product variant propagation failed', [
                'variant_id' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected static function propagateVariant(ProductVariant $variant): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $product = Product::query()
            ->withoutGlobalScopes()
            ->whereKey($variant->product_id)
            ->first();

        if (! $product || ! $product->isCatalogMaster()) {
            return;
        }

        app(SharedCatalogPropagationService::class)->propagateProductVariants($product);
    }

    protected static function propagateVariantDeleted(ProductVariant $variant): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $product = Product::query()
            ->withoutGlobalScopes()
            ->whereKey($variant->product_id)
            ->first();

        if (! $product || ! $product->isCatalogMaster()) {
            return;
        }

        app(SharedCatalogPropagationService::class)->propagateProductVariantDeleted($variant);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function productUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    protected function casts(): array
    {
        return [
            'price_override' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
