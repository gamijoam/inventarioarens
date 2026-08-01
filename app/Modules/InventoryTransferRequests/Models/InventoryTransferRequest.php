<?php

namespace App\Modules\InventoryTransferRequests\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'sequence',
    'document_number',
    'origin_tenant_id',
    'destination_tenant_id',
    'initiated_by_tenant_id',
    'sender_tenant_id',
    'receiver_tenant_id',
    'from_warehouse_id',
    'destination_warehouse_id',
    'sender_warehouse_id',
    'receiver_warehouse_id',
    'status',
    'flow_type',
    'logistics_mode',
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
    public const FLOW_STOCK_REQUEST = 'stock_request';

    public const FLOW_SHIPMENT_OFFER = 'shipment_offer';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ACCEPTED = 'accepted';

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'completed_at' => 'datetime',
            'logistics_mode' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferRequestItem::class);
    }

    public function guide(): HasOne
    {
        return $this->hasOne(InventoryTransferRequestGuide::class);
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

    public function senderTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'sender_tenant_id');
    }

    public function receiverTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'receiver_tenant_id');
    }

    public function senderWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'sender_warehouse_id');
    }

    public function receiverWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'receiver_warehouse_id');
    }

    public function isShipmentOffer(): bool
    {
        return $this->flow_type === self::FLOW_SHIPMENT_OFFER;
    }

    public function senderProductId(InventoryTransferRequestItem $item): ?int
    {
        return $this->isShipmentOffer() ? $item->origin_product_id : $item->destination_product_id;
    }

    public function receiverProductId(InventoryTransferRequestItem $item): ?int
    {
        return $this->isShipmentOffer() ? $item->destination_product_id : $item->origin_product_id;
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
