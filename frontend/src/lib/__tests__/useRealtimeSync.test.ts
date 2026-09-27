import { describe, expect, it, vi } from 'vitest';
import { handleRealtimeEvent } from '../useRealtimeSync';

describe('useRealtimeSync event handler', () => {
  it('invalida queries de tasa de cambio cuando recibe rate.updated', () => {
    const invalidateQueriesMock = vi.fn();
    const queryClientMock = {
      invalidateQueries: invalidateQueriesMock,
    } as any;

    handleRealtimeEvent(queryClientMock, 'rate.updated', { currency: 'USD', rate: 45.5 });

    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['pos', 'current-exchange-rates'] })
    );
    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['pos-bootstrap'] })
    );
  });

  it('invalida queries de órdenes POS cuando recibe pos.order.paid o pending', () => {
    const invalidateQueriesMock = vi.fn();
    const queryClientMock = {
      invalidateQueries: invalidateQueriesMock,
    } as any;

    handleRealtimeEvent(queryClientMock, 'pos.order.paid', { order_id: 123 });

    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['pos', 'orders'] })
    );
  });

  it('invalida sesiones de caja cuando recibe cash_register.closed', () => {
    const invalidateQueriesMock = vi.fn();
    const queryClientMock = {
      invalidateQueries: invalidateQueriesMock,
    } as any;

    handleRealtimeEvent(queryClientMock, 'cash_register.closed', { session_id: 45 });

    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['cash-registers'] })
    );
  });
});
