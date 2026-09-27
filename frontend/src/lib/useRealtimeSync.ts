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
      void queryClient.invalidateQueries({ queryKey: ['pos', 'current-exchange-rates'] });
      void queryClient.invalidateQueries({ queryKey: ['exchange-rates'] });
      void queryClient.invalidateQueries({ queryKey: ['pos-bootstrap'] });
      break;

    case 'cash_register.opened':
    case 'cash_register.closed':
      void queryClient.invalidateQueries({ queryKey: ['cash-registers'] });
      void queryClient.invalidateQueries({ queryKey: ['cash-register-sessions'] });
      void queryClient.invalidateQueries({ queryKey: ['pos-bootstrap'] });
      break;

    case 'pos.order.paid':
    case 'pos.order.pending':
    case 'pos.order.cancelled':
      void queryClient.invalidateQueries({ queryKey: ['pos', 'orders'] });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'pending-orders'] });
      void queryClient.invalidateQueries({ queryKey: ['sales'] });
      break;
  }
}

/**
 * Hook de React para sincronización en tiempo real vía WebSockets.
 * Se suscribe al canal del tenant activo y despacha invalidaciones automáticas.
 */
export function useRealtimeSync(tenantId?: number | null): void {
  const queryClient = useQueryClient();

  useEffect(() => {
    if (!tenantId) return;

    const channel = `tenant:${tenantId}`;
    const ws = getWsHubClient();

    ws.subscribe(channel);

    const unsubs = [
      ws.on('rate.updated', (data) => handleRealtimeEvent(queryClient, 'rate.updated', data)),
      ws.on('cash_register.opened', (data) => handleRealtimeEvent(queryClient, 'cash_register.opened', data)),
      ws.on('cash_register.closed', (data) => handleRealtimeEvent(queryClient, 'cash_register.closed', data)),
      ws.on('pos.order.paid', (data) => handleRealtimeEvent(queryClient, 'pos.order.paid', data)),
      ws.on('pos.order.pending', (data) => handleRealtimeEvent(queryClient, 'pos.order.pending', data)),
      ws.on('pos.order.cancelled', (data) => handleRealtimeEvent(queryClient, 'pos.order.cancelled', data)),
    ];

    return () => {
      unsubs.forEach((unsub) => unsub());
      ws.unsubscribe(channel);
    };
  }, [tenantId, queryClient]);
}
