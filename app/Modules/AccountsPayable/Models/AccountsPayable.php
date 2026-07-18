<?php

namespace App\Modules\AccountsPayable\Models;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Suppliers\Models\Supplier;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'supplier_id',
    'purchase_order_id',
    'status',
    'document_number',
    'currency',
    'exchange_rate_type_id',
    'exchange_rate_type_code',
    'exchange_rate',
    'original_base_amount',
    'original_local_amount',
    'returned_base_amount',
    'returned_local_amount',
    'paid_base_amount',
    'paid_local_amount',
    'adjusted_base_amount',
    'adjusted_local_amount',
    'balance_base_amount',
    'balance_local_amount',
    'due_date',
    'opened_at',
    'paid_at',
])]
class AccountsPayable extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    protected $table = 'accounts_payables';

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
            'original_base_amount' => 'decimal:4',
            'original_local_amount' => 'decimal:4',
            'returned_base_amount' => 'decimal:4',
            'returned_local_amount' => 'decimal:4',
            'paid_base_amount' => 'decimal:4',
            'paid_local_amount' => 'decimal:4',
            'adjusted_base_amount' => 'decimal:4',
            'adjusted_local_amount' => 'decimal:4',
            'balance_base_amount' => 'decimal:4',
            'balance_local_amount' => 'decimal:4',
            'due_date' => 'date',
            'opened_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function exchangeRateType(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountsPayablePayment::class, 'accounts_payable_id');
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(AccountsPayablePaymentRequest::class, 'accounts_payable_id');
    }
}
