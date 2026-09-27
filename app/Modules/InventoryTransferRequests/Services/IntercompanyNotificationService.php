<?php

namespace App\Modules\InventoryTransferRequests\Services;

use App\Models\User;
use App\Modules\InventoryTransferRequests\Events\IntercompanyNotificationCreated;
use App\Modules\InventoryTransferRequests\Models\IntercompanyNotification;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Support\Realtime\WsHub;
use Illuminate\Support\Facades\Log;
use Throwable;

class IntercompanyNotificationService
{
    public const CREATED = 'created';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const PREPARED = 'prepared';

    public const DISPATCHED = 'dispatched';

    public const DELIVERED = 'delivered';

    public const RECEIVED = 'received';

    public function record(InventoryTransferRequest $request, string $eventType, User $actor): IntercompanyNotification
    {
        $request->loadMissing(['originTenant', 'destinationTenant', 'senderTenant', 'receiverTenant']);
        [$tenantId, $title, $message] = $this->content($request, $eventType);

        $notification = IntercompanyNotification::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'inventory_transfer_request_id' => $request->id,
                'event_type' => $eventType,
            ],
            [
                'title' => $title,
                'message' => $message,
                'action_url' => '/inventory-transfer-requests/'.$request->id,
                'actor_user_id' => $actor->id,
                'metadata' => [
                    'document_number' => $request->document_number,
                    'flow_type' => $request->flow_type,
                    'sender_tenant_id' => $request->sender_tenant_id,
                    'receiver_tenant_id' => $request->receiver_tenant_id,
                ],
                'occurred_at' => now(),
            ]
        );

        if ($notification->wasRecentlyCreated) {
            try {
                event(IntercompanyNotificationCreated::fromModel($notification));
            } catch (Throwable $exception) {
                Log::warning('No se pudo emitir la notificacion interempresa en tiempo real.', [
                    'notification_id' => $notification->id,
                    'exception' => $exception->getMessage(),
                ]);
            }

            $this->publishWsHub($notification, $request, $eventType, $tenantId, $title, $message);
        }

        return $notification;
    }

    private function publishWsHub(
        IntercompanyNotification $notification,
        InventoryTransferRequest $request,
        string $eventType,
        int $tenantId,
        string $title,
        string $message
    ): void {
        try {
            $payload = [
                'id' => (int) $notification->id,
                'tenant_id' => (int) $tenantId,
                'inventory_transfer_request_id' => (int) $request->id,
                'event_type' => $eventType,
                'title' => $title,
                'message' => $message,
                'action_url' => $notification->action_url,
                'occurred_at' => $notification->occurred_at?->toISOString() ?? now()->toISOString(),
                'origin_tenant_id' => (int) $request->origin_tenant_id,
                'destination_tenant_id' => (int) $request->destination_tenant_id,
                'origin_tenant_name' => $request->originTenant?->name,
                'destination_tenant_name' => $request->destinationTenant?->name,
                'document_number' => $request->document_number,
                'status' => $request->status,
                'response_notes' => $request->response_notes,
            ];

            // 1. Canal de la empresa que recibe la notificacion directa
            WsHub::publishTenant($tenantId, 'intercompany.notification', $payload);
            WsHub::publishTenant($tenantId, "inventory-transfer-requests.{$eventType}", $payload);

            // 2. Notificar tambien a la otra empresa para sincronizar sus listados y estados
            $otherTenantId = ($tenantId === (int) $request->origin_tenant_id)
                ? (int) $request->destination_tenant_id
                : (int) $request->origin_tenant_id;
            if ($otherTenantId && $otherTenantId !== $tenantId) {
                WsHub::publishTenant($otherTenantId, "inventory-transfer-requests.{$eventType}", $payload);
            }

            // 3. Canal de grupo (para Tienda Padre / Matriz si pertenece a un grupo)
            $groupOriginId = $request->originTenant?->parent_id ?? ($request->originTenant?->isGroup() ? $request->originTenant->id : null);
            $groupDestId = $request->destinationTenant?->parent_id ?? ($request->destinationTenant?->isGroup() ? $request->destinationTenant->id : null);
            $groupIds = array_values(array_unique(array_filter([(int) $groupOriginId, (int) $groupDestId])));
            foreach ($groupIds as $gid) {
                WsHub::publish("group:{$gid}", 'intercompany.notification', $payload);
                WsHub::publish("group:{$gid}", "inventory-transfer-requests.{$eventType}", $payload);
            }
        } catch (Throwable $e) {
            Log::warning('[WsHub] Error emitiendo notificacion interempresa', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function content(InventoryTransferRequest $request, string $eventType): array
    {
        $originName = $request->originTenant?->name ?? 'Otra empresa';
        $destinationName = $request->destinationTenant?->name ?? 'Otra empresa';
        $senderName = $request->senderTenant?->name ?? 'La empresa remitente';
        $receiverName = $request->receiverTenant?->name ?? 'La empresa receptora';
        $document = $request->document_number;

        return match ($eventType) {
            self::CREATED => [
                (int) $request->destination_tenant_id,
                $request->isShipmentOffer() ? 'Nueva propuesta de envío' : 'Nueva solicitud de stock',
                $request->isShipmentOffer()
                    ? "{$originName} propone enviarte mercancía en {$document}."
                    : "{$originName} solicita mercancía en {$document}.",
            ],
            self::ACCEPTED => [
                (int) $request->origin_tenant_id,
                $request->isShipmentOffer() ? 'Propuesta de envío aceptada' : 'Solicitud de stock aceptada',
                "{$destinationName} aceptó {$document}.",
            ],
            self::REJECTED => [(int) $request->origin_tenant_id, 'Solicitud interempresa rechazada', "{$destinationName} rechazó {$document}."],
            self::CANCELLED => [(int) $request->destination_tenant_id, 'Solicitud interempresa cancelada', "{$originName} canceló {$document}."],
            self::PREPARED => [(int) $request->receiver_tenant_id, 'Envío preparado', "{$senderName} preparó la mercancía de {$document}."],
            self::DISPATCHED => [(int) $request->receiver_tenant_id, 'Mercancía despachada', "{$senderName} despachó {$document}."],
            self::DELIVERED => [(int) $request->receiver_tenant_id, 'Mercancía entregada', "{$document} fue entregada. Confirma la recepción."],
            self::RECEIVED => [(int) $request->sender_tenant_id, 'Mercancía recibida', "{$receiverName} confirmó la recepción de {$document}."],
            default => throw new \InvalidArgumentException("Tipo de notificación interempresa no soportado: {$eventType}"),
        };
    }
}
