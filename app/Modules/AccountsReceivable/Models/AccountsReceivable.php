<?php

namespace App\Modules\AccountsReceivable\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'sale_id',
    'status',
    'document_number',
    'currency',
    'original_base_amount',
    'original_local_amount',
    'returned_base_amount',
    'returned_local_amount',
    'collected_base_amount',
    'collected_local_amount',
    'adjusted_base_amount',
    'adjusted_local_amount',
    'balance_base_amount',
    'balance_local_amount',
    'due_date',
    'opened_at',
    'paid_at',
])]
class AccountsReceivable extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    protected $table = 'accounts_receivables';

    protected function casts(): array
    {
        return [
            'original_base_amount' => 'decimal:4',
            'original_local_amount' => 'decimal:4',
            'returned_base_amount' => 'decimal:4',
            'returned_local_amount' => 'decimal:4',
            'collected_base_amount' => 'decimal:4',
            'collected_local_amount' => 'decimal:4',
            'adjusted_base_amount' => 'decimal:4',
            'adjusted_local_amount' => 'decimal:4',
            'balance_base_amount' => 'decimal:4',
            'balance_local_amount' => 'decimal:4',
            'due_date' => 'date',
            'opened_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountsReceivablePayment::class, 'accounts_receivable_id');
    }
}
