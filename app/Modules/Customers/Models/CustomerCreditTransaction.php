<?php

namespace App\Modules\Customers\Models;

use App\Models\User;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'customer_id',
    'type',
    'currency',
    'amount',
    'amount_base',
    'amount_local',
    'source_type',
    'source_id',
    'operation_key',
    'created_by',
    'notes',
])]
class CustomerCreditTransaction extends Model
{
    use BelongsToTenant;

    public const TYPE_ISSUED = 'issued';

    public const TYPE_APPLIED = 'applied';

    public const TYPE_REFUNDED = 'refunded';

    public const TYPE_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'amount_base' => 'decimal:4',
            'amount_local' => 'decimal:4',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
