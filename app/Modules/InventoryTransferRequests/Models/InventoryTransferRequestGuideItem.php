<?php

namespace App\Modules\InventoryTransferRequests\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'guide_id',
    'inventory_transfer_request_item_id',
    'prepared_quantity',
    'received_quantity',
    'prepared_serial_units',
    'received_serial_units',
    'difference_reason',
])]
class InventoryTransferRequestGuideItem extends Model
{
    public function guide(): BelongsTo
    {
        return $this->belongsTo(InventoryTransferRequestGuide::class, 'guide_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(InventoryTransferRequestItem::class, 'inventory_transfer_request_item_id');
    }

    protected function casts(): array
    {
        return [
            'prepared_quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'prepared_serial_units' => 'array',
            'received_serial_units' => 'array',
        ];
    }
}
