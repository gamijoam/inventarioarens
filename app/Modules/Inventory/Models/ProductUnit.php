<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'warehouse_id',
    'serial_type',
    'serial_number',
    'status',
    'acquired_stock_movement_id',
    'released_stock_movement_id',
])]
class ProductUnit extends Model
{
    use BelongsToTenant;

    public const SERIAL_TYPE_SERIAL = 'serial';

    public const SERIAL_TYPE_IMEI = 'imei';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_SOLD = 'sold';

    public const STATUS_DAMAGED = 'damaged';

    public const STATUS_REMOVED = 'removed';

    public const STATUS_WARRANTY_HOLD = 'warranty_hold';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function acquiredStockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'acquired_stock_movement_id');
    }

    public function releasedStockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'released_stock_movement_id');
    }
}
