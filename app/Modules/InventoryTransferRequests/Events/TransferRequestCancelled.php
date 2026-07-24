<?php

namespace App\Modules\InventoryTransferRequests\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Difundido cuando el ORIGEN cancela una solicitud de traslado antes de
 * que el destino la responda. Se envia al tenant DESTINO para
 * notificarle que la solicitud fue retirada.
 *
 * Canal privado del tenant destino: `tenant.{destination_id}`.
 *
 * NO usa `SerializesModels` ni guarda el modelo Eloquent. Ver
 * TransferRequestCreated::fromModel() para el rationale completo.
 */
class TransferRequestCancelled implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $requestId,
        public readonly int $originTenantId,
        public readonly int $destinationTenantId,
    ) {}

    public static function fromModel(\App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest $r): self
    {
        return new self(
            (int) $r->id,
            (int) $r->origin_tenant_id,
            (int) $r->destination_tenant_id,
        );
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.'.$this->destinationTenantId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventory-transfer-requests.cancelled';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->requestId,
            'origin_tenant_id' => $this->originTenantId,
            'destination_tenant_id' => $this->destinationTenantId,
        ];
    }
}
