import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const navigate = vi.fn();
const markRead = vi.fn();
const markAllRead = vi.fn();
const useNotifications = vi.fn();
const useUnreadCount = vi.fn();

vi.mock('@tanstack/react-router', () => ({
  useNavigate: () => navigate,
}));

vi.mock('@/stores/session', () => ({
  useSessionStore: (selector: (state: { tenant: { id: number } }) => unknown) =>
    selector({ tenant: { id: 7 } }),
}));

vi.mock('./api', () => ({
  useIntercompanyNotifications: () => useNotifications(),
  useUnreadIntercompanyNotificationsCount: () => useUnreadCount(),
  useMarkIntercompanyNotificationRead: () => ({ mutate: markRead }),
  useMarkAllIntercompanyNotificationsRead: () => ({ mutate: markAllRead }),
}));

vi.mock('./useIntercompanyNotificationBroadcast', () => ({
  useIntercompanyNotificationBroadcast: vi.fn(),
}));

import { IntercompanyNotificationBell } from './IntercompanyNotificationBell';

describe('<IntercompanyNotificationBell>', () => {
  beforeEach(() => {
    navigate.mockReset();
    markRead.mockReset();
    markAllRead.mockReset();
    useUnreadCount.mockReturnValue({ data: 1 });
    useNotifications.mockReturnValue({
      isLoading: false,
      data: [
        {
          id: 31,
          inventory_transfer_request_id: 12,
          event_type: 'prepared',
          title: 'Envio preparado',
          message: 'La empresa remitente preparo la mercancia.',
          action_url: '/inventory-transfer-requests/12',
          is_read: false,
          occurred_at: '2026-08-03T12:00:00Z',
        },
      ],
    });
  });

  it('muestra el contador y permite abrir la solicitud notificada', async () => {
    const user = userEvent.setup();
    render(<IntercompanyNotificationBell />);

    const trigger = screen.getByRole('button', { name: 'Notificaciones interempresa' });
    expect(trigger).toHaveTextContent('1');

    await user.click(trigger);
    const notification = await screen.findByText('Envio preparado');
    await user.click(notification);

    expect(markRead).toHaveBeenCalledWith(31);
    expect(navigate).toHaveBeenCalledWith({
      to: '/inventory-transfer-requests/$requestId',
      params: { requestId: '12' },
    });
  });

  it('permite marcar toda la bandeja como leida', async () => {
    const user = userEvent.setup();
    render(<IntercompanyNotificationBell />);

    await user.click(screen.getByRole('button', { name: 'Notificaciones interempresa' }));
    await user.click(await screen.findByRole('button', { name: /Marcar le/ }));

    expect(markAllRead).toHaveBeenCalledOnce();
  });
});
