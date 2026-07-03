<?php

namespace App\Modules\InventoryTransferRequests\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sequence',
    'document_number',
    'origin_tenant_id',
    'destination_tenant_id',
    'from_warehouse_id',
    'destination_warehouse_id',
    'status',
    'reason',
    'reference',
    'notes',
    'response_notes',
    'requested_by',
    'responded_by',
    'requested_at',
    'responded_at',
    'completed_at',
])]
class InventoryTransferRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferRequestItem::class);
    }

    public function originTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'origin_tenant_id');
    }

    public function destinationTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'destination_tenant_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
