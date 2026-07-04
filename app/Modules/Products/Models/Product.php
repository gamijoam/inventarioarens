<?php

namespace App\Modules\Products\Models;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'sku',
    'tracking_type',
    'base_price',
    'sale_currency',
    'sale_exchange_rate_type_id',
    'warranty_policy_id',
    'is_active',
])]
class Product extends Model
{
    use BelongsToTenant;

    public const TRACKING_QUANTITY = 'quantity';
    public const TRACKING_SERIALIZED = 'serialized';

    public const CURRENCY_USD = 'USD';
    public const CURRENCY_VES = 'VES';

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ProductAudit::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
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
}
