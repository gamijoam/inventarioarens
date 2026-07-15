<?php

namespace App\Modules\InventoryTransfers\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InventoryTransferDriver: datos del transportista (driver) asociado
 * a un InventoryTransfer. NO requiere user en el sistema: el
 * transportista es un actor externo (firma desde su telefono o al
 * momento de la entrega).
 *
 * La relacion con InventoryTransfer es 1:1 (un solo driver por
 * traslado). Se puede asignar antes del dispatch (planning) o al
 * momento de la entrega (campo).
 */
class InventoryTransferDriver extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'inventory_transfer_id',
        'name',
        'document_number',
        'phone',
        'vehicle_plate',
        'carrier_company',
        'picked_up_at',
        'delivered_at',
        'signed_by_driver_at',
        'signature_driver_url',
        'signed_by_receiver_at',
        'signature_receiver_url',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'signed_by_driver_at' => 'datetime',
            'signed_by_receiver_at' => 'datetime',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    /**
     * Indica si el driver ya firmo (true si signed_by_driver_at esta set).
     */
    public function isDriverSigned(): bool
    {
        return $this->signed_by_driver_at !== null;
    }

    /**
     * Indica si el receptor ya firmo (true si signed_by_receiver_at esta set).
     */
    public function isReceiverSigned(): bool
    {
        return $this->signed_by_receiver_at !== null;
    }
}
