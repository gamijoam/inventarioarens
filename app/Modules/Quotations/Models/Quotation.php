<?php

namespace App\Modules\Quotations\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sequence',
    'document_number',
    'customer_id',
    'customer_name',
    'warehouse_id',
    'status',
    'valid_until',
    'notes',
    'subtotal_base_amount',
    'subtotal_local_amount',
    'discount_base_amount',
    'discount_local_amount',
    'total_base_amount',
    'total_local_amount',
    'exchange_rate_type_id',
    'exchange_rate_type_code',
    'exchange_rate',
    'created_by',
    'issued_at',
    'converted_at',
    'converted_pos_order_id',
])]
class Quotation extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CONVERTED = 'converted';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ISSUED,
        self::STATUS_CANCELLED,
        self::STATUS_CONVERTED,
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'subtotal_base_amount' => 'decimal:4',
            'subtotal_local_amount' => 'decimal:4',
            'discount_base_amount' => 'decimal:4',
            'discount_local_amount' => 'decimal:4',
            'total_base_amount' => 'decimal:4',
            'total_local_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'issued_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id')->withoutGlobalScopes();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function convertedPosOrder(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'converted_pos_order_id');
    }
}
