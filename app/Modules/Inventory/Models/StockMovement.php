<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'warehouse_id',
    'product_id',
    'type',
    'quantity',
    'unit_cost',
    'reason',
    'reference_type',
    'reference_id',
    'created_by',
])]
class StockMovement extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'purchase',
        'purchase_return',
        'sale',
        'sale_return',
        'adjustment_in',
        'adjustment_out',
        'transfer_in',
        'transfer_out',
        'transfer_request_in',
        'transfer_request_out',
        'return_in',
        'return_out',
        'damaged',
        'reserved',
        'released',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
