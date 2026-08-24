<?php

namespace App\Modules\SalesReversals\Models;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id',
    'pos_order_id',
    'cash_register_session_id',
    'created_by',
    'type',
    'reason',
    'original_paid_at',
    'effective_at',
    'reversed_base_amount',
    'reversed_local_amount',
])]
class SaleReversal extends Model
{
    use BelongsToTenant;

    public const TYPE_VOID = 'void';

    public const TYPE_REVERSAL = 'reversal';

    protected function casts(): array
    {
        return [
            'original_paid_at' => 'datetime',
            'effective_at' => 'datetime',
            'reversed_base_amount' => 'decimal:4',
            'reversed_local_amount' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function posOrder(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class);
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
