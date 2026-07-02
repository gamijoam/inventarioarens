<?php

namespace App\Modules\CashRegister\Models;

use App\Models\User;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cash_register_session_id',
    'type',
    'method',
    'currency',
    'amount',
    'amount_base',
    'amount_local',
    'exchange_rate_type_id',
    'exchange_rate_type_code',
    'exchange_rate',
    'source_type',
    'source_id',
    'reference',
    'notes',
    'created_by',
])]
class CashRegisterMovement extends Model
{
    use BelongsToTenant;

    public const TYPE_OPENING = 'opening';
    public const TYPE_INFLOW = 'inflow';
    public const TYPE_OUTFLOW = 'outflow';
    public const TYPE_POS_PAYMENT = 'pos_payment';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const METHOD_CASH = 'cash';
    public const METHOD_CARD = 'card';
    public const METHOD_MOBILE_PAYMENT = 'mobile_payment';
    public const METHOD_TRANSFER = 'transfer';
    public const METHOD_ZELLE = 'zelle';
    public const METHOD_EXTERNAL_FINANCING = 'external_financing';
    public const METHOD_OTHER = 'other';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'amount_base' => 'decimal:4',
            'amount_local' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
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
