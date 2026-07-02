<?php

namespace App\Modules\AccountsReceivable\Models;

use App\Models\User;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'accounts_receivable_id',
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
    'created_by',
    'paid_at',
])]
class AccountsReceivablePayment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'amount_base' => 'decimal:4',
            'amount_local' => 'decimal:4',
            'paid_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountsReceivable::class, 'accounts_receivable_id');
    }

    public function exchangeRateType(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
