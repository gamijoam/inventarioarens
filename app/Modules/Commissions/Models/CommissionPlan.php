<?php

namespace App\Modules\Commissions\Models;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'name',
    'beneficiary_role',
    'percentage',
    'conversion_policy',
    'exchange_rate_type_id',
    'credit_policy',
    'maturation_days',
    'allow_self_stacking',
    'include_combos',
    'include_discounts',
    'is_active',
    'starts_at',
    'ends_at',
])]
class CommissionPlan extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'include_combos' => true,
        'include_discounts' => true,
    ];

    public const ROLE_SELLER = 'seller';

    public const ROLE_CASHIER = 'cashier';

    public const CONVERSION_SALE_SNAPSHOT = 'sale_snapshot';

    public const CONVERSION_CONFIGURED_RATE = 'configured_rate';

    public const CREDIT_PROPORTIONAL_COLLECTIONS = 'proportional_collections';

    public const CREDIT_SALE_CONFIRMATION = 'sale_confirmation';

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:4',
            'maturation_days' => 'integer',
            'allow_self_stacking' => 'boolean',
            'include_combos' => 'boolean',
            'include_discounts' => 'boolean',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CommissionPlanAssignment::class);
    }

    public function exchangeRateType(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class);
    }

    public function scopeActiveAt(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }
}
