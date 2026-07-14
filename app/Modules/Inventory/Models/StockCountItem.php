<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\WarehouseLocation;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_count_id',
    'product_id',
    'location_id',
    'system_quantity',
    'counted_quantity',
    'variance',
    'status',
    'notes',
    'counted_at',
    'counted_by',
])]
class StockCountItem extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COUNTED = 'counted';

    public const STATUS_ADJUSTED = 'adjusted';

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
