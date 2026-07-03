<?php

namespace App\Modules\Sales\Models;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id',
    'warehouse_id',
    'product_id',
    'quantity',
    'sale_currency',
    'unit_price',
    'total_amount',
    'base_unit_price',
    'base_total_amount',
    'exchange_rate_type_id',
    'exchange_rate_type_code',
    'exchange_rate',
    'stock_movement_id',
    'warranty_policy_id',
    'warranty_policy_name',
    'warranty_duration_days',
    'warranty_coverage_type',
    'warranty_conditions',
    'warranty_starts_at',
    'warranty_expires_at',
])]
class SaleItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'base_unit_price' => 'decimal:4',
            'base_total_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'warranty_starts_at' => 'datetime',
            'warranty_expires_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function exchangeRateType(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
