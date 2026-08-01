<?php

namespace App\Modules\Products\Models;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
