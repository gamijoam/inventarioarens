import { useNavigate } from '@tanstack/react-router';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { toast } from 'sonner';

import { initEcho } from '@/lib/echo';
import { intercompanyNotificationKeys } from './api';

interface NotificationEvent {
  id: number;
  tenant_id: number;
  inventory_transfer_request_id: number;
  title: string;
  message: string;
}

export function useIntercompanyNotificationBroadcast(tenantId?: number) {
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  useEffect(() => {
    if (!tenantId) return;
    const echo = initEcho();
    if (!echo) return;

    const channelName = `tenant.${tenantId}`;
    const channel = echo.private(channelName);
    const handler = (event: NotificationEvent) => {
      if (event.tenant_id !== tenantId) return;
      void queryClient.invalidateQueries({ queryKey: intercompanyNotificationKeys.all });
      toast.info(event.title, {
        description: event.message,
        duration: 10_000,
        action: {
          label: 'Ver',
          onClick: () => void navigate({
            to: '/inventory-transfer-requests/$requestId',
            params: { requestId: String(event.inventory_transfer_request_id) },
          }),
        },
      });
    };

    channel.listen('.inventory-transfer-notifications.created', handler);
    return () => {
      channel.stopListening('.inventory-transfer-notifications.created', handler);
      echo.leave(channelName);
    };
  }, [navigate, queryClient, tenantId]);
}
