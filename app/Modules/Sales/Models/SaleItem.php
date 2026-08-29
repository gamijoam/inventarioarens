<?php

namespace App\Modules\Sales\Models;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id',
    'warehouse_id',
    'product_id',
    'product_variant_id',
    'price_list_id',
    'promotion_id',
    'promotion_code',
    'promotion_name',
    'promotion_benefit_type',
    'promotion_price_usd',
    'promotion_discount_percent',
    'promotion_discount_amount_usd',
    'promotion_adjustment_base_amount',
    'promotion_adjustment_local_amount',
    'price_list_name',
    'quantity',
    'sale_currency',
    'unit_price',
    'total_amount',
    'base_unit_price',
    'base_total_amount',
    'local_total_amount',
    'fiscal_tax_code',
    'fiscal_tax_source',
    'fiscal_tax_override_code',
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
    'base_unit_cost',
    'exchange_rate_type_id',
    'exchange_rate_type_code',
    'exchange_rate',
    'stock_movement_id',
    'product_unit_ids',
    'discount_type',
    'discount_value',
    'discount_amount',
    'discount_base_amount',
    'discount_local_amount',
    'discount_reason',
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
            'local_total_amount' => 'decimal:4',
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
            'base_unit_cost' => 'decimal:4',
            'promotion_price_usd' => 'decimal:4',
            'promotion_discount_percent' => 'decimal:2',
            'promotion_discount_amount_usd' => 'decimal:4',
            'promotion_adjustment_base_amount' => 'decimal:4',
            'promotion_adjustment_local_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'product_unit_ids' => 'array',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'discount_base_amount' => 'decimal:4',
            'discount_local_amount' => 'decimal:4',
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

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
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
