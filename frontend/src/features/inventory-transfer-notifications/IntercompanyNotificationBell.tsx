import { useNavigate } from '@tanstack/react-router';
import { Bell, CheckCheck } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/DropdownMenu';
import { useSessionStore } from '@/stores/session';
import {
  useIntercompanyNotifications,
  useMarkAllIntercompanyNotificationsRead,
  useMarkIntercompanyNotificationRead,
  useUnreadIntercompanyNotificationsCount,
} from './api';
import { useIntercompanyNotificationBroadcast } from './useIntercompanyNotificationBroadcast';

export function IntercompanyNotificationBell() {
  const navigate = useNavigate();
  const tenantId = useSessionStore((state) => state.tenant?.id);
  const notifications = useIntercompanyNotifications(Boolean(tenantId));
  const unread = useUnreadIntercompanyNotificationsCount(Boolean(tenantId));
  const markRead = useMarkIntercompanyNotificationRead();
  const markAllRead = useMarkAllIntercompanyNotificationsRead();
  useIntercompanyNotificationBroadcast(tenantId);

  const count = unread.data ?? 0;
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon-sm" className="relative" aria-label="Notificaciones interempresa">
          <Bell className="size-4" aria-hidden="true" />
          {count > 0 && (
            <span className="bg-info text-info-foreground absolute -right-1 -top-1 flex min-w-4 items-center justify-center rounded-full px-1 text-[9px] font-semibold leading-4">
              {count > 99 ? '99+' : count}
            </span>
          )}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-[min(24rem,calc(100vw-2rem))] p-0">
        <div className="flex items-center justify-between px-3 py-2">
          <DropdownMenuLabel className="p-0 text-sm">Actividad interempresa</DropdownMenuLabel>
          {count > 0 && (
            <Button variant="ghost" size="sm" onClick={() => markAllRead.mutate()}>
              <CheckCheck className="size-4" />
              Marcar leídas
            </Button>
          )}
        </div>
        <DropdownMenuSeparator className="m-0" />
        <div className="max-h-96 overflow-y-auto p-1">
          {notifications.isLoading ? (
            <p className="text-text-muted px-3 py-6 text-center text-sm">Cargando notificaciones...</p>
          ) : (notifications.data?.length ?? 0) === 0 ? (
            <p className="text-text-muted px-3 py-6 text-center text-sm">No hay actividad reciente.</p>
          ) : (
            notifications.data?.map((notification) => (
              <DropdownMenuItem
                key={notification.id}
                className="items-start gap-3 px-3 py-2.5"
                onSelect={() => {
                  markRead.mutate(notification.id);
                  void navigate({
                    to: '/inventory-transfer-requests/$requestId',
                    params: { requestId: String(notification.inventory_transfer_request_id) },
                  });
                }}
              >
                <span className={`mt-1.5 size-2 shrink-0 rounded-full ${notification.is_read ? 'bg-border' : 'bg-info'}`} />
                <span className="min-w-0">
                  <span className="block text-sm font-medium">{notification.title}</span>
                  <span className="text-text-muted block text-xs leading-5">{notification.message}</span>
                </span>
              </DropdownMenuItem>
            ))
          )}
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
