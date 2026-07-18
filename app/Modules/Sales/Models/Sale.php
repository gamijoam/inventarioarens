<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Customers\Models\Customer;
use App\Modules\POS\Models\PosOrder;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'status',
    'customer_id',
    'total_base_amount',
    'total_local_amount',
    'created_by',
    'confirmed_at',
    'cancelled_at',
])]
class Sale extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_base_amount' => 'decimal:4',
            'total_local_amount' => 'decimal:4',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function posOrder(): HasOne
    {
        return $this->hasOne(PosOrder::class);
    }

    public function receivable(): HasOne
    {
        return $this->hasOne(AccountsReceivable::class);
    }
}
