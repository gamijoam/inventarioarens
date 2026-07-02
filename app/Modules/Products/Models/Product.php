<?php

namespace App\Modules\Products\Models;

use App\Modules\Inventory\Models\ProductUnit;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sku', 'tracking_type', 'is_active'])]
class Product extends Model
{
    use BelongsToTenant;

    public const TRACKING_QUANTITY = 'quantity';
    public const TRACKING_SERIALIZED = 'serialized';

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function requiresSerializedTracking(): bool
    {
        return $this->tracking_type === self::TRACKING_SERIALIZED;
    }
}
