<?php

namespace App\Modules\InventoryTransferRequests\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Difundido cuando se crea una nueva solicitud de traslado inter-empresa.
 *
 * Canal privado por tenant destino: `private-tenant.{destination_id}`.
 *
 * IMPORTANTE: este evento NO usa `SerializesModels` ni guarda el modelo
 * Eloquent. En su lugar recibe SOLO datos primitivos en el constructor.
 * Esto evita que Reverb intente serializar el modelo entero (con sus
 * relaciones cargadas via eager-load) al hacer broadcast, lo que puede
 * causar stack overflow o memory exhaustion y matar el proceso Reverb
 * local. El `InventoryTransferRequest` que se pasa desde el service ya
 * tiene `with(['items.originProduct', 'items.destinationProduct'])`
 * aplicado, y serializarlo con `SerializesModels` intenta serializar
 * toda la jerarquia recursivamente. Por seguridad, aqui se descompone
 * el modelo en sus campos primitivos.
 */
class TransferRequestCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $requestId,
        public readonly int $originTenantId,
        public readonly int $destinationTenantId,
        public readonly ?string $requestedAt,
    ) {}

    public static function fromModel(\App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest $r): self
    {
        $iso = null;
        if ($r->requested_at instanceof \DateTimeInterface) {
            $iso = $r->requested_at->format(\DateTimeInterface::ATOM);
        } elseif (is_string($r->requested_at) && $r->requested_at !== '') {
            $iso = Carbon::parse($r->requested_at)->toIso8601String();
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
            new PrivateChannel('tenant.'.$this->destinationTenantId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventory-transfer-requests.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->requestId,
            'origin_tenant_id' => $this->originTenantId,
            'destination_tenant_id' => $this->destinationTenantId,
            'requested_at' => $this->requestedAt,
        ];
    }
}
