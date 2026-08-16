<?php

namespace App\Modules\Promotions\Models;

use App\Models\User;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sale_id',
    'promotion_id',
    'slot',
    'scope',
    'status',
    'instance_uuid',
    'requested_by',
    'validated_by',
    'requested_at',
    'validated_at',
    'rejected_at',
    'promotion_code',
    'promotion_name',
    'benefit_type',
    'payment_currency',
    'price_usd',
    'discount_percent',
    'discount_amount_usd',
    'conditions_snapshot',
    'base_before_amount',
    'local_before_amount',
    'base_adjustment_amount',
    'local_adjustment_amount',
    'base_after_amount',
    'local_after_amount',
])]
class SalePromotionApplication extends Model
{
    use BelongsToTenant;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'validated_at' => 'datetime',
            'rejected_at' => 'datetime',
            'conditions_snapshot' => 'array',
            'price_usd' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'discount_amount_usd' => 'decimal:4',
            'base_before_amount' => 'decimal:4',
            'local_before_amount' => 'decimal:4',
            'base_adjustment_amount' => 'decimal:4',
            'local_adjustment_amount' => 'decimal:4',
            'base_after_amount' => 'decimal:4',
            'local_after_amount' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalePromotionApplicationItem::class);
    }
}
