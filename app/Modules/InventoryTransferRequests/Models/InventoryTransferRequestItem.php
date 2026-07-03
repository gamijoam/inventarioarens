<?php

namespace App\Modules\InventoryTransferRequests\Models;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'inventory_transfer_request_id',
    'origin_product_id',
    'destination_product_id',
    'quantity',
    'product_unit_ids',
    'serial_units',
    'out_stock_movement_id',
    'in_stock_movement_id',
])]
class InventoryTransferRequestItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'product_unit_ids' => 'array',
            'serial_units' => 'array',
        ];
    }

    public function transferRequest(): BelongsTo
    {
        return $this->belongsTo(InventoryTransferRequest::class, 'inventory_transfer_request_id');
    }

    public function originProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'origin_product_id');
    }

    public function destinationProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'destination_product_id');
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
