<?php

namespace App\Modules\SalesReturns\Models;

use App\Models\User;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sale_id',
    'status',
    'reason',
    'created_by',
    'reviewed_by',
    'reviewed_at',
    'rejection_reason',
    'processed_by',
    'processed_at',
    'cancelled_by',
    'cancelled_at',
    'cancellation_reason',
    'refund_currency',
    'refund_amount',
    'refund_exchange_rate_type_id',
    'refund_exchange_rate_type_code',
    'refund_exchange_rate',
    'refund_amount_base',
    'refund_amount_local',
    'refund_method',
    'refund_reference',
    'refund_cash_register_movement_id',
    'refund_financial_adjustment_id',
    'process_notes',
])]
class SalesReturn extends Model
{
    use BelongsToTenant;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'sales_returns';

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'processed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refund_amount' => 'decimal:4',
            'refund_exchange_rate' => 'decimal:6',
            'refund_amount_base' => 'decimal:4',
            'refund_amount_local' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }
}
