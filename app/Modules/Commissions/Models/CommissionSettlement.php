<?php

namespace App\Modules\Commissions\Models;

use App\Models\User;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'settlement_uuid', 'beneficiary_user_id', 'status', 'payment_currency',
    'total_base_amount', 'total_local_amount', 'payment_amount',
    'exchange_rate_type_id', 'exchange_rate_type_code', 'exchange_rate',
    'reference', 'notes', 'paid_by', 'paid_at',
])]
class CommissionSettlement extends Model
{
    use BelongsToTenant;

    public const STATUS_PAID = 'paid';

    protected function casts(): array
    {
        return [
            'total_base_amount' => 'decimal:4',
            'total_local_amount' => 'decimal:4',
            'payment_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'paid_at' => 'datetime',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommissionSettlementItem::class);
    }
}
