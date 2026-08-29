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
    'sync_source_node_code',
    'sync_source_id',
    'sales_return_id',
    'sale_item_id',
    'warehouse_id',
    'product_id',
    'fiscal_tax_source',
    'fiscal_tax_override_code',
    'fiscal_tax_code',
    'fiscal_tax_name',
    'fiscal_tax_category',
    'fiscal_tax_rate',
    'fiscal_prices_include_tax',
    'fiscal_taxable_base_amount',
    'fiscal_taxable_local_amount',
    'fiscal_exempt_base_amount',
    'fiscal_exempt_local_amount',
    'fiscal_exonerated_base_amount',
    'fiscal_exonerated_local_amount',
    'fiscal_non_taxable_base_amount',
    'fiscal_non_taxable_local_amount',
    'fiscal_tax_base_amount',
    'fiscal_tax_local_amount',
    'fiscal_total_base_amount',
    'fiscal_total_local_amount',
    'fiscal_snapshot_at',
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
            'fiscal_tax_rate' => 'decimal:4',
            'fiscal_prices_include_tax' => 'boolean',
            'fiscal_taxable_base_amount' => 'decimal:4',
            'fiscal_taxable_local_amount' => 'decimal:4',
            'fiscal_exempt_base_amount' => 'decimal:4',
            'fiscal_exempt_local_amount' => 'decimal:4',
            'fiscal_exonerated_base_amount' => 'decimal:4',
            'fiscal_exonerated_local_amount' => 'decimal:4',
            'fiscal_non_taxable_base_amount' => 'decimal:4',
            'fiscal_non_taxable_local_amount' => 'decimal:4',
            'fiscal_tax_base_amount' => 'decimal:4',
            'fiscal_tax_local_amount' => 'decimal:4',
            'fiscal_total_base_amount' => 'decimal:4',
            'fiscal_total_local_amount' => 'decimal:4',
            'fiscal_snapshot_at' => 'datetime',
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
