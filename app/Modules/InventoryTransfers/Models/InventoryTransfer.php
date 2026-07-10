<?php

namespace App\Modules\InventoryTransfers\Models;

use App\Models\User;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'sequence',
    'document_number',
    'guide_number',
    'type',
    'validation_mode',
    'from_warehouse_id',
    'to_warehouse_id',
    'status',
    'reason',
    'reference',
    'notes',
    'created_by',
    'prepared_by',
    'dispatched_by',
    'received_by',
    'processed_at',
    'requested_at',
    'prepared_at',
    'dispatched_at',
    'received_at',
    'cancelled_at',
    'cancelled_by',
    'resolution_status',
    'resolution_notes',
    'resolved_at',
    'resolved_by',
])]
class InventoryTransfer extends Model
{
    use BelongsToTenant;

    public const TYPE_INTERNAL = 'internal';
    public const TYPE_INTER_COMPANY = 'inter_company';

    public const TYPES = [
        self::TYPE_INTERNAL,
    ];

    public const VALIDATION_SIMPLE = 'simple';
    public const VALIDATION_LOGISTICS = 'logistics';

    public const VALIDATION_MODES = [
        self::VALIDATION_SIMPLE,
        self::VALIDATION_LOGISTICS,
    ];

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_IN_PREPARATION = 'in_preparation';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_PREPARED_WITH_DIFFERENCES = 'prepared_with_differences';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_IN_RECEPTION = 'in_reception';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_DIFFERENCES = 'completed_with_differences';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const RESOLUTION_UNRESOLVED = 'unresolved';
    public const RESOLUTION_PARTIAL = 'partial';
    public const RESOLUTION_RESOLVED = 'resolved';

    public const RESOLUTION_STATUSES = [
        self::RESOLUTION_UNRESOLVED,
        self::RESOLUTION_PARTIAL,
        self::RESOLUTION_RESOLVED,
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'requested_at' => 'datetime',
            'prepared_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class);
    }

    public function guide(): HasOne
    {
        return $this->hasOne(InventoryTransferGuide::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
