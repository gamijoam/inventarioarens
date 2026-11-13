<?php

namespace App\Modules\Inventory\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_lot_id',
    'stock_movement_id',
    'quantity',
])]
class StockLotMovement extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }
}
