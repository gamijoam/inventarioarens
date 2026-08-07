<?php

namespace App\Modules\Promotions\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'name',
    'code',
    'benefit_type',
    'price_currency',
    'price_usd',
    'discount_percent',
    'discount_amount_usd',
    'priority',
    'is_active',
    'starts_at',
    'ends_at',
])]
class Promotion extends Model
{
    use BelongsToTenant;

    public const BENEFIT_PERCENT_DISCOUNT = 'percent_discount';

    public const BENEFIT_FIXED_DISCOUNT = 'fixed_discount';

    public const BENEFIT_FIXED_ITEM_PRICE = 'fixed_item_price';

    public const BENEFIT_FIXED_BUNDLE_PRICE = 'fixed_bundle_price';

    public const BENEFIT_FREE_ITEM = 'free_item';

    public const BENEFIT_BUY_X_GET_Y = 'buy_x_get_y';

    public const PRICE_CURRENCY_USD = 'USD';

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class);
    }

    protected function casts(): array
    {
        return [
            'price_usd' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'discount_amount_usd' => 'decimal:4',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function scopeActiveAt(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $at);
            });
    }
}
