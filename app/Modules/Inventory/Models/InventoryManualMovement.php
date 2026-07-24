<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryManualMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'warehouse_id',
        'product_id',
        'quantity',
        'type',
        'reason',
        'notes',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'stock_movement_id',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function stockMovement()
    {
        return $this->belongsTo(StockMovement::class);
    }

}
