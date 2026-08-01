/**
 * Hook React que se suscribe al canal privado del tenant y dispara
 * toasts info cuando llegan eventos de traslados inter-empresa:
 *
 *  - `inventory-transfer-requests.created` (llega al destino)
 *  - `inventory-transfer-requests.accepted` (llega al origen)
 *  - `inventory-transfer-requests.rejected` (llega al origen)
 *  - `inventory-transfer-requests.cancelled` (llega al destino)
 *
 * Implementa la parte WebSocket del push in-app. Si Echo no esta
 * disponible, el polling reactivo de 30s cubre el caso (no se rompe).
 *
 * Diagnostico de "a veces no llegan": el frontend ahora escucha TODOS
 * los eventos en lugar de uno solo (era el bug principal). Ademas, si
 * Echo se desconecta (Reverb caido, navegador en background), pusher-js
 * intenta reconectar automaticamente cada 60s; durante ese tiempo cae
 * al polling de 30s como fallback. El usuario sigue viendo updates.
 */

import { useEffect } from 'react';

import { initEcho } from '@/lib/echo';
import { transferRequestKeys } from '@/features/inventory-transfer-requests/api';
import { useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { useNavigate } from '@tanstack/react-router';

type TransferRequestEvent = {
  id: number;
  origin_tenant_id: number;
  destination_tenant_id: number;
  requested_at?: string;
  responded_at?: string;
  response_notes?: string | null;
};

const EVENT_NAMES = [
  'inventory-transfer-requests.created',
  'inventory-transfer-requests.accepted',
  'inventory-transfer-requests.rejected',
  'inventory-transfer-requests.cancelled',
] as const;

const TOAST_BY_EVENT: Record<(typeof EVENT_NAMES)[number], {
  title: (e: TransferRequestEvent) => string;
  description: (e: TransferRequestEvent) => string;
  target: (e: TransferRequestEvent) => 'origin' | 'destination';
}> = {
  'inventory-transfer-requests.created': {
    title: () => 'Nueva solicitud de traslado inter-empresa pendiente.',
    description: (e) => `Solicitud #${e.id} recibida.`,
    target: (e) => (e.destination_tenant_id !== undefined ? 'destination' : 'origin'),
  },
  'inventory-transfer-requests.accepted': {
    title: () => 'Tu solicitud de traslado fue aceptada.',
    description: (e) => `La solicitud #${e.id} fue procesada por el destino.`,
    target: () => 'origin',
  },
  'inventory-transfer-requests.rejected': {
    title: () => 'Tu solicitud de traslado fue rechazada.',
    description: (e) =>
      e.response_notes
        ? `Solicitud #${e.id}. Motivo: ${e.response_notes}`
        : `Solicitud #${e.id} rechazada.`,
    target: () => 'origin',
  },
  'inventory-transfer-requests.cancelled': {
    title: () => 'La solicitud de traslado fue cancelada.',
    description: (e) => `La solicitud #${e.id} fue retirada por el origen.`,
    target: () => 'destination',
  },
};

export function useTransferRequestBroadcast(
  currentTenantId: number | undefined,
  options: { enabled?: boolean } = {},
): void {
  const { enabled = true } = options;
  const qc = useQueryClient();
  const navigate = useNavigate();

  useEffect(() => {
    if (!enabled || typeof currentTenantId !== 'number' || currentTenantId <= 0) {
      return;
    }

    const echo = initEcho();
    if (!echo) {
      return;
    }

    const channelName = `tenant.${currentTenantId}`;
    const channel = echo.private(channelName);

    const handleEvent = (eventType: (typeof EVENT_NAMES)[number]) =>
      (event: TransferRequestEvent) => {
        // Debug en consola: ayuda al usuario a verificar que los
        // eventos WebSocket realmente llegan al cliente. Si ve esto
        // en consola, Reverb + Echo funcionan. Si no ve nada, es cache
        // del navegador o Reverb no esta corriendo.
        if (typeof window !== 'undefined' && window.console) {
          // eslint-disable-next-line no-console
          window.console.info(
            `[ITR] WebSocket event received: ${eventType}`,
            { event, currentTenantId },
          );
        }

        const cfg = TOAST_BY_EVENT[eventType];

        // Invalidar caches para que el sidebar y el listado se
        // actualicen al instante.
        void qc.invalidateQueries({ queryKey: transferRequestKeys.unreadCounts() });
        void qc.invalidateQueries({ queryKey: transferRequestKeys.lists() });

        // Filtro de routing: el evento `created` se envia al tenant
        // destino y `cancelled` al destino; `accepted` y `rejected` al
        // origen. Solo mostramos el toast si el evento es para nuestro
        // tenant (aunque el canal privado ya filtra por el canal policy,
        // este filtro defensivo evita toasts duplicados en escenarios
        // multi-tenant).
        const target = cfg.target(event);
        const isForMe = target === 'destination'
          ? event.destination_tenant_id === currentTenantId
          : event.origin_tenant_id === currentTenantId;
        if (!isForMe) return;

        toast.info(cfg.title(event), {
          duration: Infinity,
          dismissible: true,
          description: cfg.description(event),
          action: {
            label: 'Ver',
            onClick: () => {
              void navigate({ to: '/inventory-transfer-requests' });
            },
          },
        });
      };

    const handlers = EVENT_NAMES.map((eventName) => ({
      eventName,
      handler: handleEvent(eventName),
    }));

    for (const { eventName, handler } of handlers) {
      // Laravel Echo usa dot-prefixed event names para broadcastAs.
      channel.listen(`.${eventName}`, handler);
    }

    return () => {
      for (const { eventName, handler } of handlers) {
        channel.stopListening(`.${eventName}`, handler);
      }
      echo.leave(channelName);
    };
  }, [enabled, currentTenantId, qc, navigate]);
}
