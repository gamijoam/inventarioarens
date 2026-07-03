<?php

namespace App\Modules\InventoryTransfers\Models;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'inventory_transfer_id',
    'product_id',
    'quantity',
    'out_stock_movement_id',
    'in_stock_movement_id',
    'product_unit_ids',
])]
class InventoryTransferItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'product_unit_ids' => 'array',
        ];
    }

    public function inventoryTransfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function outStockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'out_stock_movement_id');
    }

    public function inStockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'in_stock_movement_id');
    }
}
