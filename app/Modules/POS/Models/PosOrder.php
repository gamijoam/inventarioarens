<?php

namespace App\Modules\POS\Models;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Customers\Models\Customer;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sale_id',
    'cash_register_session_id',
    'customer_id',
    'status',
    'cashier_id',
    'customer_name',
    'total_base_amount',
    'total_local_amount',
    'paid_base_amount',
    'paid_local_amount',
    'opened_at',
    'paid_at',
    'closed_at',
])]
class PosOrder extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
            'total_base_amount' => 'decimal:4',
            'total_local_amount' => 'decimal:4',
            'paid_base_amount' => 'decimal:4',
            'paid_local_amount' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PosPayment::class);
    }
}
