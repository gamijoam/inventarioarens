import { useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';

import { getWsHubClient } from '@/lib/wsHub';
import { intercompanyNotificationKeys } from './api';
import { transferRequestKeys } from '@/features/inventory-transfer-requests/api';

export function useIntercompanyNotificationBroadcast(
  tenantId?: number,
  enabled = true,
  groupId?: number | null,
) {
  const queryClient = useQueryClient();

  useEffect(() => {
    if (!tenantId || !enabled) return;

    const ws = getWsHubClient();
    const tenantChannel = `tenant:${tenantId}`;
    ws.subscribe(tenantChannel);

    const groupChannel = groupId ? `group:${groupId}` : null;
    if (groupChannel) {
      ws.subscribe(groupChannel);
    }

    // La campana conserva sus lecturas e historial. Los avisos emergentes
    // de transferencias se muestran únicamente desde useRealtimeSync.
    const refreshNotifications = () => {
      void queryClient.invalidateQueries({ queryKey: intercompanyNotificationKeys.all });
      void queryClient.invalidateQueries({ queryKey: transferRequestKeys.all });
    };

    const unsubs = [
      ws.on('intercompany.notification', refreshNotifications),
      ws.on('inventory-transfer-notifications.created', refreshNotifications),
    ];

    return () => {
      unsubs.forEach((unsub) => unsub());
      ws.unsubscribe(tenantChannel);
      if (groupChannel) {
        ws.unsubscribe(groupChannel);
      }
    };
  }, [queryClient, tenantId, enabled, groupId]);
}
