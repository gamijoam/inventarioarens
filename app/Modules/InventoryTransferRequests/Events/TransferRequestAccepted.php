<?php

namespace App\Modules\InventoryTransferRequests\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Difundido cuando el DESTINATARIO acepta una solicitud de traslado.
 * Se envia al tenant ORIGEN (el que la creo) para notificarle que su
 * solicitud fue aceptada.
 *
 * Canal privado del tenant origen: `tenant.{origin_id}`.
 *
 * NO usa `SerializesModels` ni guarda el modelo Eloquent: solo datos
 * primitivos. Evita que Reverb intente serializar el modelo entero
 * (con sus relaciones eager-loaded) al hacer broadcast, lo que mata el
 * proceso Reverb local con stack overflow o memory exhaustion. Ver
 * TransferRequestCreated::fromModel() para el rationale completo.
 */
class TransferRequestAccepted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $requestId,
        public readonly int $originTenantId,
        public readonly int $destinationTenantId,
        public readonly ?string $respondedAt,
    ) {}

    public static function fromModel(\App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest $r): self
    {
        $iso = null;
        if ($r->responded_at instanceof \DateTimeInterface) {
            $iso = $r->responded_at->format(\DateTimeInterface::ATOM);
        } elseif (is_string($r->responded_at) && $r->responded_at !== '') {
            $iso = Carbon::parse($r->responded_at)->toIso8601String();
        }

        return new self(
            (int) $r->id,
            (int) $r->origin_tenant_id,
            (int) $r->destination_tenant_id,
            $iso,
        );
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.'.$this->originTenantId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventory-transfer-requests.accepted';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->requestId,
            'origin_tenant_id' => $this->originTenantId,
            'destination_tenant_id' => $this->destinationTenantId,
            'responded_at' => $this->respondedAt,
        ];
    }
}
