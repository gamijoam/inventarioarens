<?php

namespace App\Modules\InventoryTransferRequests\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'transport_mode',
    'inventory_transfer_request_id',
    'status',
    'carrier_name',
    'carrier_document_number',
    'carrier_phone',
    'vehicle_plate',
    'carrier_company',
    'carrier_user_id',
    'prepared_by',
    'dispatched_by',
    'delivered_by',
    'received_by',
    'prepared_at',
    'dispatched_at',
    'delivered_at',
    'received_at',
    'notes',
    'difference_notes',
])]
class InventoryTransferRequestGuide extends Model
{
    public const TRANSPORT_SIMPLE = 'simple';

    public const TRANSPORT_CONTROLLED = 'controlled';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_RECEIVED = 'received';

    public function transferRequest(): BelongsTo
    {
        return $this->belongsTo(InventoryTransferRequest::class, 'inventory_transfer_request_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferRequestGuideItem::class, 'guide_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function carrierUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'carrier_user_id');
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    protected function casts(): array
    {
        return [
            'prepared_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
