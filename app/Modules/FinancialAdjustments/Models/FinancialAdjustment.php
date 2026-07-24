<?php

namespace App\Modules\FinancialAdjustments\Models;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sequence',
    'document_number',
    'account_type',
    'accounts_receivable_id',
    'accounts_payable_id',
    'status',
    'currency',
    'amount',
    'exchange_rate_type_id',
    'exchange_rate_type_code',
    'exchange_rate',
    'amount_base',
    'amount_local',
    'reason',
    'notes',
    'created_by',
    'applied_at',
])]
class FinancialAdjustment extends Model
{
    use BelongsToTenant;

    public const ACCOUNT_RECEIVABLE = 'receivable';

    public const ACCOUNT_PAYABLE = 'payable';

    public const STATUS_APPLIED = 'applied';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'amount_base' => 'decimal:4',
            'amount_local' => 'decimal:4',
            'applied_at' => 'datetime',
        ];
    }

    public function accountsReceivable(): BelongsTo
    {
        return $this->belongsTo(AccountsReceivable::class);
    }

    public function accountsPayable(): BelongsTo
    {
        return $this->belongsTo(AccountsPayable::class);
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
