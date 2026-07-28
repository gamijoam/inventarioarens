<?php

namespace App\Modules\CashRegister\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cash_register_session_id',
    'currency',
    'denomination',
    'quantity',
    'total_amount',
])]
class CashRegisterSessionCount extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'denomination' => 'decimal:4',
            'quantity' => 'integer',
            'total_amount' => 'decimal:4',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }
}
