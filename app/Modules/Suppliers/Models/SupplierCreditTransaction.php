<?php

namespace App\Modules\Suppliers\Models;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sync_source_node_code',
    'sync_source_id',
    'supplier_id',
    'type',
    'currency',
    'amount',
    'amount_base',
    'amount_local',
    'exchange_rate_type_id',
    'exchange_rate_type_code',
    'exchange_rate',
    'accounts_payable_id',
    'accounts_payable_payment_id',
    'purchase_return_id',
    'method',
    'reference',
    'notes',
    'created_by',
    'occurred_at',
])]
class SupplierCreditTransaction extends Model
{
    use BelongsToTenant;

    public const TYPE_RETURN_CREDIT = 'return_credit';

    public const TYPE_APPLIED = 'applied';

    public const TYPE_REFUND_RECEIVED = 'refund_received';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'amount_base' => 'decimal:4',
            'amount_local' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'occurred_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function accountsPayable(): BelongsTo
    {
        return $this->belongsTo(AccountsPayable::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
