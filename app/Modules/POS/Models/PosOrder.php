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
    'reserved_until',
    'cashier_id',
    'seller_id',
    'customer_name',
    'total_base_amount',
    'total_local_amount',
    'fiscal_taxable_base_amount',
    'fiscal_taxable_local_amount',
    'fiscal_exempt_base_amount',
    'fiscal_exempt_local_amount',
    'fiscal_exonerated_base_amount',
    'fiscal_exonerated_local_amount',
    'fiscal_non_taxable_base_amount',
    'fiscal_non_taxable_local_amount',
    'fiscal_tax_base_amount',
    'fiscal_tax_local_amount',
    'fiscal_snapshot_at',
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

    public const RESERVATION_TTL_MINUTES = 30;

    public const STATUS_VOIDED = 'voided';

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
            'reserved_until' => 'datetime',
            'total_base_amount' => 'decimal:4',
            'total_local_amount' => 'decimal:4',
            'fiscal_taxable_base_amount' => 'decimal:4',
            'fiscal_taxable_local_amount' => 'decimal:4',
            'fiscal_exempt_base_amount' => 'decimal:4',
            'fiscal_exempt_local_amount' => 'decimal:4',
            'fiscal_exonerated_base_amount' => 'decimal:4',
            'fiscal_exonerated_local_amount' => 'decimal:4',
            'fiscal_non_taxable_base_amount' => 'decimal:4',
            'fiscal_non_taxable_local_amount' => 'decimal:4',
            'fiscal_tax_base_amount' => 'decimal:4',
            'fiscal_tax_local_amount' => 'decimal:4',
            'fiscal_snapshot_at' => 'datetime',
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

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
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
