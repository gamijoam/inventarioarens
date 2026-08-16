<?php

namespace App\Modules\Promotions\Models;

use App\Modules\Sales\Models\SaleItem;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_promotion_application_id',
    'sale_item_id',
    'quantity',
    'base_before_amount',
    'local_before_amount',
    'base_adjustment_amount',
    'local_adjustment_amount',
    'base_after_amount',
    'local_after_amount',
])]
class SalePromotionApplicationItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'base_before_amount' => 'decimal:4',
            'local_before_amount' => 'decimal:4',
            'base_adjustment_amount' => 'decimal:4',
            'local_adjustment_amount' => 'decimal:4',
            'base_after_amount' => 'decimal:4',
            'local_after_amount' => 'decimal:4',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(SalePromotionApplication::class, 'sale_promotion_application_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
