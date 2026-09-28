import { useEffect } from 'react';
import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { getWsHubClient } from './wsHub';

/**
 * Procesa un evento en tiempo real recibido desde WsHub
 * e invalida de forma atómica las caches correspondientes de TanStack Query.
 */
export function handleRealtimeEvent(
  queryClient: QueryClient,
  event: string,
  _data: unknown,
): void {
  switch (event) {
    case 'rate.updated':
      void queryClient.invalidateQueries({ queryKey: ['pos', 'current-rates'] });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'current-exchange-rates'] });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'bootstrap'] });
      void queryClient.invalidateQueries({ queryKey: ['pos-bootstrap'] });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'exchange-rate-types'] });
      void queryClient.invalidateQueries({ queryKey: ['catalog', 'exchange-rates'] });
      void queryClient.invalidateQueries({ queryKey: ['exchange-rates'] });
      void queryClient.invalidateQueries({ queryKey: ['catalog', 'exchange-rate-types'] });
      void queryClient.invalidateQueries({ queryKey: ['products'] });
      break;

    case 'cash_register.opened':
    case 'cash_register.closed':
      void queryClient.invalidateQueries({ queryKey: ['cash-registers'] });
      void queryClient.invalidateQueries({ queryKey: ['cash-register-sessions'] });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'cash-registers'] });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'cash-sessions'] });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'bootstrap'] });
      void queryClient.invalidateQueries({ queryKey: ['pos-bootstrap'] });
      break;

    case 'pos.order.paid':
    case 'pos.order.pending':
    case 'pos.order.cancelled':
      void queryClient.invalidateQueries({ queryKey: ['pos', 'orders'] });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'pending-orders'] });
      void queryClient.invalidateQueries({ queryKey: ['sales'] });
      break;

    case 'intercompany.notification':
    case 'inventory-transfer-notifications.created':
      void queryClient.invalidateQueries({ queryKey: ['inventory-transfer-notifications'] });
      void queryClient.invalidateQueries({ queryKey: ['inventory-transfer-requests'] });
      break;

    case 'inventory-transfer-requests.created':
    case 'inventory-transfer-requests.accepted':
    case 'inventory-transfer-requests.rejected':
    case 'inventory-transfer-requests.cancelled':
    case 'inventory-transfer-requests.prepared':
    case 'inventory-transfer-requests.dispatched':
    case 'inventory-transfer-requests.delivered':
    case 'inventory-transfer-requests.received':
      void queryClient.invalidateQueries({ queryKey: ['inventory-transfer-requests'] });
      void queryClient.invalidateQueries({ queryKey: ['products'] });
      break;

    case 'inventory-transfer.created':
    case 'inventory-transfer.dispatched':
    case 'inventory-transfer.received':
    case 'inventory-transfer.cancelled':
      void queryClient.invalidateQueries({ queryKey: ['inventory-transfers'] });
      void queryClient.invalidateQueries({ queryKey: ['products'] });
      break;
  }
}

/**
 * Hook de React para sincronización en tiempo real vía WebSockets.
 * Se suscribe al canal del tenant activo (y opcionalmente al canal del grupo)
 * y despacha invalidaciones automáticas.
 */
export function useRealtimeSync(tenantId?: number | null, groupId?: number | null): void {
  const queryClient = useQueryClient();

  useEffect(() => {
    if (!tenantId) return;

    const channel = `tenant:${tenantId}`;
    const groupChannel = groupId ? `group:${groupId}` : null;
    const ws = getWsHubClient();

    ws.subscribe(channel);
    if (groupChannel) {
      ws.subscribe(groupChannel);
    }

    const events = [
      'rate.updated',
      'cash_register.opened',
      'cash_register.closed',
      'pos.order.paid',
      'pos.order.pending',
      'pos.order.cancelled',
      'intercompany.notification',
      'inventory-transfer-notifications.created',
      'inventory-transfer-requests.created',
      'inventory-transfer-requests.accepted',
      'inventory-transfer-requests.rejected',
      'inventory-transfer-requests.cancelled',
      'inventory-transfer-requests.prepared',
      'inventory-transfer-requests.dispatched',
      'inventory-transfer-requests.delivered',
      'inventory-transfer-requests.received',
      'inventory-transfer.created',
      'inventory-transfer.dispatched',
      'inventory-transfer.received',
      'inventory-transfer.cancelled',
    ];

    const unsubs = events.map((ev) =>
      ws.on(ev, (data) => handleRealtimeEvent(queryClient, ev, data))
    );

    return () => {
      unsubs.forEach((unsub) => unsub());
      ws.unsubscribe(channel);
      if (groupChannel) {
        ws.unsubscribe(groupChannel);
      }
    };
  }, [tenantId, groupId, queryClient]);
}
