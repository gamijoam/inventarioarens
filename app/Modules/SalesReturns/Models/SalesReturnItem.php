<?php

namespace App\Modules\SalesReturns\Models;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sales_return_id',
    'sale_item_id',
    'warehouse_id',
    'product_id',
    'quantity',
    'product_unit_ids',
    'stock_movement_id',
    'condition',
    'reason',
])]
class SalesReturnItem extends Model
{
    use BelongsToTenant;

    public const CONDITION_SELLABLE = 'sellable';

    public const CONDITION_DAMAGED = 'damaged';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'product_unit_ids' => 'array',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
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
