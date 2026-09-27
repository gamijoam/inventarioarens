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

import { getWsHubClient } from '@/lib/wsHub';
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
  options: { enabled?: boolean; groupId?: number | null } = {},
): void {
  const { enabled = true, groupId } = options;
  const qc = useQueryClient();
  const navigate = useNavigate();

  useEffect(() => {
    if (!enabled || typeof currentTenantId !== 'number' || currentTenantId <= 0) {
      return;
    }

    const ws = getWsHubClient();
    const tenantChannel = `tenant:${currentTenantId}`;
    ws.subscribe(tenantChannel);

    const groupChannel = groupId ? `group:${groupId}` : null;
    if (groupChannel) {
      ws.subscribe(groupChannel);
    }

    const handleEvent = (eventType: (typeof EVENT_NAMES)[number]) =>
      (event: TransferRequestEvent) => {
        void qc.invalidateQueries({ queryKey: transferRequestKeys.unreadCounts() });
        void qc.invalidateQueries({ queryKey: transferRequestKeys.lists() });

        const cfg = TOAST_BY_EVENT[eventType];
        if (!cfg) return;

        const target = cfg.target(event);
        const isForMe = target === 'destination'
          ? event.destination_tenant_id === currentTenantId
          : event.origin_tenant_id === currentTenantId;

        if (isForMe || (groupId && event.origin_tenant_id !== currentTenantId && event.destination_tenant_id !== currentTenantId)) {
          toast.info(cfg.title(event), {
            duration: 10_000,
            dismissible: true,
            description: cfg.description(event),
            action: {
              label: 'Ver',
              onClick: () => {
                void navigate({ to: '/inventory-transfer-requests' });
              },
            },
          });
        }
      };

    const unsubs = EVENT_NAMES.map((eventName) =>
      ws.on(eventName, handleEvent(eventName))
    );

    return () => {
      unsubs.forEach((unsub) => unsub());
      ws.unsubscribe(tenantChannel);
      if (groupChannel) {
        ws.unsubscribe(groupChannel);
      }
    };
  }, [enabled, currentTenantId, groupId, qc, navigate]);
}
