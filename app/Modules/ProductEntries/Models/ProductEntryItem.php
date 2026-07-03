<?php

namespace App\Modules\ProductEntries\Models;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_entry_id',
    'warehouse_id',
    'product_id',
    'quantity',
    'unit_cost',
    'stock_movement_id',
    'serial_units',
])]
class ProductEntryItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'serial_units' => 'array',
        ];
    }

    public function productEntry(): BelongsTo
    {
        return $this->belongsTo(ProductEntry::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
