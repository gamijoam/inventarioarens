import { useNavigate } from '@tanstack/react-router';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { toast } from 'sonner';

import { getWsHubClient } from '@/lib/wsHub';
import { intercompanyNotificationKeys } from './api';
import { transferRequestKeys } from '@/features/inventory-transfer-requests/api';

interface NotificationEvent {
  id?: number;
  tenant_id?: number;
  inventory_transfer_request_id?: number;
  title?: string;
  message?: string;
}

export function useIntercompanyNotificationBroadcast(
  tenantId?: number,
  enabled = true,
  groupId?: number | null,
) {
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  useEffect(() => {
    if (!tenantId || !enabled) return;

    const ws = getWsHubClient();
    const tenantChannel = `tenant:${tenantId}`;
    ws.subscribe(tenantChannel);

    const groupChannel = groupId ? `group:${groupId}` : null;
    if (groupChannel) {
      ws.subscribe(groupChannel);
    }

    const handler = (event: NotificationEvent) => {
      void queryClient.invalidateQueries({ queryKey: intercompanyNotificationKeys.all });
      void queryClient.invalidateQueries({ queryKey: transferRequestKeys.all });

      if (event?.title) {
        toast.info(event.title, {
          description: event.message,
          duration: 10_000,
          action: event.inventory_transfer_request_id
            ? {
                label: 'Ver',
                onClick: () =>
                  void navigate({
                    to: '/inventory-transfer-requests/$requestId',
                    params: { requestId: String(event.inventory_transfer_request_id) },
                  }),
              }
            : undefined,
        });
      }
    };

    const unsubs = [
      ws.on('intercompany.notification', handler),
      ws.on('inventory-transfer-notifications.created', handler),
    ];

    return () => {
      unsubs.forEach((unsub) => unsub());
      ws.unsubscribe(tenantChannel);
      if (groupChannel) {
        ws.unsubscribe(groupChannel);
      }
    };
  }, [navigate, queryClient, tenantId, enabled, groupId]);
}
