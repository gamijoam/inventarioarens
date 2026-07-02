<?php

namespace App\Modules\PaymentReceipts\Models;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\AccountsReceivable\Models\AccountsReceivablePayment;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'sequence',
    'receipt_number',
    'type',
    'status',
    'source_type',
    'source_id',
    'accounts_receivable_payment_id',
    'accounts_payable_payment_id',
    'party_type',
    'party_id',
    'party_name',
    'party_document_type',
    'party_document_number',
    'payment_currency',
    'amount',
    'amount_base',
    'amount_local',
    'exchange_rate_type_code',
    'exchange_rate',
    'method',
    'reference',
    'notes',
    'issued_by',
    'issued_at',
    'voided_by',
    'voided_at',
    'void_reason',
])]
class PaymentReceipt extends Model
{
    use BelongsToTenant;

    public const TYPE_CUSTOMER_COLLECTION = 'customer_collection';
    public const TYPE_SUPPLIER_PAYMENT = 'supplier_payment';

    public const STATUS_ISSUED = 'issued';
    public const STATUS_VOIDED = 'voided';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'amount_base' => 'decimal:4',
            'amount_local' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function accountsReceivablePayment(): BelongsTo
    {
        return $this->belongsTo(AccountsReceivablePayment::class);
    }

    public function accountsPayablePayment(): BelongsTo
    {
        return $this->belongsTo(AccountsPayablePayment::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
