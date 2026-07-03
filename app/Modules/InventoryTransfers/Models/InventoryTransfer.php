<?php

namespace App\Modules\InventoryTransfers\Models;

use App\Models\User;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sequence',
    'document_number',
    'type',
    'from_warehouse_id',
    'to_warehouse_id',
    'status',
    'reason',
    'reference',
    'notes',
    'created_by',
    'processed_at',
])]
class InventoryTransfer extends Model
{
    use BelongsToTenant;

    public const TYPE_INTERNAL = 'internal';
    public const TYPE_INTER_COMPANY = 'inter_company';

    public const TYPES = [
        self::TYPE_INTERNAL,
    ];

    public const STATUS_COMPLETED = 'completed';

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class);
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
}
