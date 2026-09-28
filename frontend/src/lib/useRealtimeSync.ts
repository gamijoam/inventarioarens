import { useEffect } from 'react';
import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { getWsHubClient } from './wsHub';

const recentToasts = new Set<string>();
function showToastOnce(key: string, fn: () => void) {
  if (recentToasts.has(key)) return;
  recentToasts.add(key);
  setTimeout(() => recentToasts.delete(key), 5000);
  fn();
}

/**
 * Procesa un evento en tiempo real recibido desde WsHub
 * e invalida de forma atómica las caches correspondientes de TanStack Query,
 * además de emitir notificaciones toast contextuales.
 */
export function handleRealtimeEvent(
  queryClient: QueryClient,
  event: string,
  _data: unknown,
): void {
  switch (event) {
    case 'rate.updated':
      void queryClient.invalidateQueries({ queryKey: ['pos', 'current-rates'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'current-exchange-rates'] });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'bootstrap'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['pos-bootstrap'] });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'exchange-rate-types'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['catalog', 'exchange-rates'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['exchange-rates'] });
      void queryClient.invalidateQueries({ queryKey: ['catalog', 'exchange-rate-types'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['products'], refetchType: 'active' });
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
    case 'inventory-transfer-notifications.created': {
      void queryClient.invalidateQueries({ queryKey: ['inventory-transfer-notifications'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['inventory-transfer-requests'], refetchType: 'active' });

      if (_data && typeof _data === 'object') {
        const payload = _data as Record<string, unknown>;
        const title = payload.title ? String(payload.title) : '';
        const message = payload.message ? String(payload.message) : '';
        const id = payload.id ? String(payload.id) : `${title}-${message}`;
        if (title) {
          showToastOnce(`notif-${id}`, () => {
            toast.info(title, {
              description: message || undefined,
              duration: 8000,
            });
          });
        }
      }
      break;
    }

    case 'inventory-transfer-requests.created':
    case 'inventory-transfer-requests.accepted':
    case 'inventory-transfer-requests.rejected':
    case 'inventory-transfer-requests.cancelled':
    case 'inventory-transfer-requests.prepared':
    case 'inventory-transfer-requests.dispatched':
    case 'inventory-transfer-requests.delivered':
    case 'inventory-transfer-requests.received': {
      void queryClient.invalidateQueries({ queryKey: ['inventory-transfer-requests'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['inventory-transfer-notifications'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['products'], refetchType: 'active' });

      if (_data && typeof _data === 'object') {
        const payload = _data as Record<string, unknown>;
        const title = payload.title ? String(payload.title) : '';
        const message = payload.message ? String(payload.message) : '';
        const id = payload.id ? String(payload.id) : (payload.inventory_transfer_request_id ? `${payload.inventory_transfer_request_id}-${payload.event_type}` : `${title}-${message}`);
        if (title) {
          showToastOnce(`itr-${id}`, () => {
            toast.info(title, {
              description: message || undefined,
              duration: 8000,
            });
          });
        }
      }
      break;
    }

    case 'inventory-transfer.created':
    case 'inventory-transfer.prepared':
    case 'inventory-transfer.dispatched':
    case 'inventory-transfer.received':
    case 'inventory-transfer.cancelled': {
      void queryClient.invalidateQueries({ queryKey: ['transfers'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['inventory-transfers'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['products'], refetchType: 'active' });

      if (_data && typeof _data === 'object') {
        const payload = _data as Record<string, unknown>;
        const doc = (payload.document_number as string) || (payload.guide_number as string) || '';
        const actionLabels: Record<string, string> = {
          created: 'creado',
          prepared: 'preparado',
          dispatched: 'despachado',
          received: 'recibido',
          cancelled: 'cancelado',
        };
        const actionKey = (payload.action as string) || event.split('.')[1] || 'actualizado';
        const actionText = actionLabels[actionKey] ?? actionKey;
        const fromWh = (payload.from_warehouse_name as string) || '';
        const toWh = (payload.to_warehouse_name as string) || '';
        const route = fromWh && toWh ? `${fromWh} ➔ ${toWh}` : '';

        const toastKey = `trf-${payload.id ?? doc}-${actionKey}`;
        showToastOnce(toastKey, () => {
          toast.info(`Traslado interno ${actionText}: ${doc}`, {
            description: route || undefined,
            duration: 6000,
          });
        });
      }
      break;
    }

    case 'products.prices.updated': {
      void queryClient.invalidateQueries({ queryKey: ['products'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'bootstrap'], refetchType: 'active' });
      void queryClient.invalidateQueries({ queryKey: ['pos', 'current-rates'], refetchType: 'active' });

      if (_data && typeof _data === 'object') {
        const payload = _data as Record<string, unknown>;
        const count = payload.items_count ? String(payload.items_count) : '';
        const reverted = Boolean(payload.reverted);
        const title = reverted ? 'Precios revertidos' : 'Precios actualizados en tiempo real';
        const desc = count ? `${count} producto(s) actualizados.` : undefined;
        showToastOnce(`prices-upd-${payload.adjustment_id ?? Date.now()}-${reverted ? 'rev' : 'app'}`, () => {
          toast.success(title, {
            description: desc,
            duration: 6000,
          });
        });
      }
      break;
    }
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
      'inventory-transfer.prepared',
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
