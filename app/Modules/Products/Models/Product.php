<?php

namespace App\Modules\Products\Models;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'description',
    'long_description',
    'sku',
    'barcode',
    'unit_of_measure',
    'track_stock',
    'tracking_type',
    'brand_id',
    'base_price',
    'profit_margin',
    'sale_currency',
    'sale_exchange_rate_type_id',
    'min_stock',
    'max_stock',
    'reorder_quantity',
    'average_cost',
    'last_purchase_cost',
    'image_url',
    'warranty_policy_id',
    'is_active',
])]
class Product extends Model
{
    use BelongsToTenant;

    /** Margen de ganancia por defecto cuando el admin no define uno custom. */
    public const DEFAULT_PROFIT_MARGIN = 25.0;

    public const TRACKING_QUANTITY = 'quantity';

    public const TRACKING_SERIALIZED = 'serialized';

    public const CURRENCY_USD = 'USD';

    public const CURRENCY_VES = 'VES';

    public const UNIT_UNIT = 'unit';

    public const UNIT_KG = 'kg';

    public const UNIT_LT = 'lt';

    public const UNIT_M = 'm';

    public const ALLOWED_UNITS = [
        self::UNIT_UNIT,
        self::UNIT_KG,
        self::UNIT_LT,
        self::UNIT_M,
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:4',
            'profit_margin' => 'decimal:2',
            'min_stock' => 'decimal:4',
            'max_stock' => 'decimal:4',
            'reorder_quantity' => 'decimal:4',
            'average_cost' => 'decimal:4',
            'last_purchase_cost' => 'decimal:4',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Margen de ganancia efectivo (custom o default global).
     * Si `profit_margin` es null, NO se recalcula `base_price` automaticamente.
     */
    public function effectiveProfitMargin(): ?float
    {
        if ($this->profit_margin === null) {
            return null;
        }
        return (float) $this->profit_margin;
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ProductAudit::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort')->orderBy('id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_category')
            ->withPivot('tenant_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_tag')
            ->withPivot('tenant_id');
    }

    public function saleExchangeRateType(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class, 'sale_exchange_rate_type_id');
    }

    public function warrantyPolicy(): BelongsTo
    {
        return $this->belongsTo(WarrantyPolicy::class);
    }

    public function requiresSerializedTracking(): bool
    {
        return $this->tracking_type === self::TRACKING_SERIALIZED;
    }

    public function hasMinStock(): bool
    {
        return $this->min_stock !== null;
    }

    public function hasMaxStock(): bool
    {
        return $this->max_stock !== null;
    }
}
