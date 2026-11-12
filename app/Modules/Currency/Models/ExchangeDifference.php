<?php

namespace App\Modules\Currency\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'sync_source_node_code',
    'sync_source_id',
    'account_type',
    'accounts_receivable_id',
    'accounts_payable_id',
    'document_number',
    'payment_type',
    'payment_id',
    'document_currency',
    'payment_currency',
    'document_rate',
    'settlement_rate',
    'applied_base_amount',
    'applied_local_amount',
    'paid_base_amount',
    'paid_local_amount',
    'difference_base_amount',
    'difference_local_amount',
    'kind',
    'occurred_at',
])]
class ExchangeDifference extends Model
{
    use BelongsToTenant;

    public const ACCOUNT_RECEIVABLE = 'receivable';

    public const ACCOUNT_PAYABLE = 'payable';

    public const KIND_GAIN = 'gain';

    public const KIND_LOSS = 'loss';

    protected function casts(): array
    {
        return [
            'document_rate' => 'decimal:6',
            'settlement_rate' => 'decimal:6',
            'applied_base_amount' => 'decimal:4',
            'applied_local_amount' => 'decimal:4',
            'paid_base_amount' => 'decimal:4',
            'paid_local_amount' => 'decimal:4',
            'difference_base_amount' => 'decimal:4',
            'difference_local_amount' => 'decimal:4',
            'occurred_at' => 'datetime',
        ];
    }
}
