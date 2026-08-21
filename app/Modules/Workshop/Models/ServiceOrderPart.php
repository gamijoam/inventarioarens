<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pieza usada o por usar en una orden de servicio.
 */
#[Fillable([
    'service_order_id',
    'product_id',
    'product_variant_id',
    'warehouse_id',
    'quantity',
    'unit_cost',
    'unit_price',
    'base_unit_price',
    'base_unit_cost',
    'stock_movement_id',
    'status',
    'created_by',
])]
class ServiceOrderPart extends Model
{
    use BelongsToTenant;

    protected $table = 'service_order_parts';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'base_unit_price' => 'decimal:4',
            'base_unit_cost' => 'decimal:4',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
