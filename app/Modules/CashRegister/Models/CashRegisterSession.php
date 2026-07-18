<?php

namespace App\Modules\CashRegister\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'cash_register_id',
    'cashier_id',
    'opened_by',
    'closed_by',
    'status',
    'opening_base_amount',
    'opening_local_amount',
    'expected_base_amount',
    'expected_local_amount',
    'counted_base_amount',
    'counted_local_amount',
    'difference_base_amount',
    'difference_local_amount',
    'opened_at',
    'closed_at',
    'notes',
    'closing_notes',
])]
class CashRegisterSession extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_base_amount' => 'decimal:4',
            'opening_local_amount' => 'decimal:4',
            'expected_base_amount' => 'decimal:4',
            'expected_local_amount' => 'decimal:4',
            'counted_base_amount' => 'decimal:4',
            'counted_local_amount' => 'decimal:4',
            'difference_base_amount' => 'decimal:4',
            'difference_local_amount' => 'decimal:4',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashRegisterMovement::class);
    }
}
