<?php

namespace App\Modules\AccountsPayable\Models;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'accounts_payable_id',
    'accounts_payable_payment_id',
    'status',
    'payment_currency',
    'amount',
    'exchange_rate_type_id',
    'exchange_rate_type_code',
    'exchange_rate',
    'amount_base',
    'amount_local',
    'method',
    'reference',
    'notes',
    'scheduled_for',
    'cash_register_session_id',
    'prepared_by',
    'approved_by',
    'rejected_by',
    'cancelled_by',
    'executed_by',
    'prepared_at',
    'approved_at',
    'rejected_at',
    'cancelled_at',
    'executed_at',
    'rejection_reason',
    'cancellation_reason',
])]
class AccountsPayablePaymentRequest extends Model
{
    use BelongsToTenant;

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXECUTED = 'executed';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'amount_base' => 'decimal:4',
            'amount_local' => 'decimal:4',
            'scheduled_for' => 'datetime',
            'prepared_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountsPayable::class, 'accounts_payable_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(AccountsPayablePayment::class, 'accounts_payable_payment_id');
    }

    public function exchangeRateType(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class);
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
