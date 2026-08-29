<?php

namespace App\Modules\Quotations\Models;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'quotation_id',
    'product_id',
    'product_variant_id',
    'product_name',
    'sku',
    'quantity',
    'price_list_id',
    'unit_price_base',
    'unit_price_local',
    'discount_base',
    'discount_local',
    'total_base',
    'total_local',
    'sort_order',
])]
class QuotationItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price_base' => 'decimal:4',
            'unit_price_local' => 'decimal:4',
            'discount_base' => 'decimal:4',
            'discount_local' => 'decimal:4',
            'total_base' => 'decimal:4',
            'total_local' => 'decimal:4',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
