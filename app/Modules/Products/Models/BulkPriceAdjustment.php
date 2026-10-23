<?php

namespace App\Modules\Products\Models;

use App\Models\User;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'user_id',
    'name',
    'target',
    'price_list_id',
    'adjustment_type',
    'adjustment_value',
    'rounding',
    'filters',
    'items_count',
    'snapshots',
    'status',
    'reverted_at',
    'reverted_by',
])]
class BulkPriceAdjustment extends Model
{
    use BelongsToTenant;

    public const TARGET_BASE_PRICE = 'base_price';
    public const TARGET_PRICE_LIST = 'price_list';

    public const TYPE_PERCENTAGE_INCREASE = 'percentage_increase';
    public const TYPE_PERCENTAGE_DECREASE = 'percentage_decrease';
    public const TYPE_FIXED_AMOUNT = 'fixed_amount';
    public const TYPE_MARKUP_ON_COST = 'markup_on_cost';

    public const STATUS_APPLIED = 'applied';
    public const STATUS_REVERTED = 'reverted';

    protected function casts(): array
    {
        return [
            'adjustment_value' => 'decimal:4',
            'filters' => 'array',
            'snapshots' => 'array',
            'items_count' => 'integer',
            'reverted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
