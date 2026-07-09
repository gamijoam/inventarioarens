<?php

namespace App\Modules\InventoryTransfers\Models;

use App\Models\User;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'inventory_transfer_id',
    'guide_number',
    'status',
    'issued_at',
    'prepared_at',
    'dispatched_at',
    'received_at',
    'issued_by',
    'prepared_by',
    'dispatched_by',
    'received_by',
    'metadata',
    'notes',
])]
class InventoryTransferGuide extends Model
{
    use BelongsToTenant;

    public const STATUS_GENERATED = 'generated';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_PREPARED_WITH_DIFFERENCES = 'prepared_with_differences';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_DIFFERENCES = 'completed_with_differences';

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'prepared_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(InventoryTransferChecklist::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
