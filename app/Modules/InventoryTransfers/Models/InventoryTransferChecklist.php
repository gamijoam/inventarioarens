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
    'inventory_transfer_guide_id',
    'stage',
    'status',
    'assigned_to',
    'completed_by',
    'completed_at',
    'notes',
])]
class InventoryTransferChecklist extends Model
{
    use BelongsToTenant;

    public const STAGE_PREPARATION = 'preparation';
    public const STAGE_RECEPTION = 'reception';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_DIFFERENCES = 'completed_with_differences';

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(InventoryTransferGuide::class, 'inventory_transfer_guide_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferChecklistItem::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
