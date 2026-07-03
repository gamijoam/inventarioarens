<?php

namespace App\Modules\Purchases\Models;

use App\Models\User;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Suppliers\Models\Supplier;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'supplier_id',
    'status',
    'document_number',
    'issued_at',
    'due_date',
    'purchase_currency',
    'exchange_rate_type_id',
    'exchange_rate_type_code',
    'exchange_rate',
    'total_base_amount',
    'total_local_amount',
    'received_base_amount',
    'received_local_amount',
    'created_by',
    'received_at',
    'cancelled_at',
])]
class PurchaseOrder extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    public const CURRENCY_USD = 'USD';
    public const CURRENCY_VES = 'VES';

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
            'total_base_amount' => 'decimal:4',
            'total_local_amount' => 'decimal:4',
            'received_base_amount' => 'decimal:4',
            'received_local_amount' => 'decimal:4',
            'issued_at' => 'date',
            'due_date' => 'date',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function exchangeRateType(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
