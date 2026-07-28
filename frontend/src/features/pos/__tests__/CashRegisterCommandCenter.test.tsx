import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockUseCashSessions = vi.fn();
const mockUseCashSessionDetail = vi.fn();

vi.mock('@/features/reports/api', () => ({
  useCashSessions: (...args: unknown[]) => {
    mockUseCashSessions(...args);
    return mockUseCashSessions();
  },
}));

vi.mock('@/permissions/useCan', () => ({
  useCan: () => true,
}));

vi.mock('../api', () => ({
  useCashSessionDetail: () => mockUseCashSessionDetail(),
  useReviewCashSession: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { CashRegisterCommandCenter } from '../CashRegisterCommandCenter';

beforeEach(() => {
  mockUseCashSessions.mockReset();
  mockUseCashSessionDetail.mockReset();
  mockUseCashSessions.mockReturnValue({
    data: {
      period: { from: '2026-07-27', to: '2026-07-27', from_datetime: '', to_datetime: '' },
      summary: {
        open_count: 1,
        closed_count: 1,
        expected_base_amount: 125,
        expected_local_amount: 4000,
        difference_base_amount: -2,
      },
      rows: [
        {
          id: 7,
          status: 'closed',
          branch_name: 'Centro',
          cash_register_name: 'Caja Principal',
          cashier_name: 'Ana',
          opening_base_amount: 50,
          opening_local_amount: 1000,
          expected_base_amount: 125,
          expected_local_amount: 4000,
          counted_base_amount: 123,
          counted_local_amount: 4000,
          difference_base_amount: -2,
          difference_local_amount: 0,
          opened_at: '2026-07-27T08:00:00Z',
          closed_at: '2026-07-27T16:00:00Z',
          movements: [
            {
              id: 1,
              type: 'pos_payment',
              method: 'cash',
              currency: 'USD',
              amount_base: 75,
              amount_local: 0,
              reference: null,
              created_at: '2026-07-27T12:00:00Z',
            },
          ],
        },
      ],
      movement_breakdown: [],
    },
    isLoading: false,
    isFetching: false,
    isError: false,
    refetch: vi.fn(),
  });
  mockUseCashSessionDetail.mockReturnValue({
    isLoading: false,
    data: {
      summary: {
        movement_count: 1,
        pos_order_count: 1,
        pos_paid_order_count: 1,
        pos_paid_base_amount: 40,
        pos_paid_local_amount: 0,
        receivable_collections_base_amount: 0,
        payable_payments_base_amount: 0,
        manual_movement_count: 0,
      },
      payment_breakdown: [
        {
          name: 'Efectivo USD',
          method: 'cash',
          currency: 'USD',
          payments_count: 1,
          amount_base: 40,
          amount_local: 0,
        },
      ],
    },
  });
});

describe('<CashRegisterCommandCenter>', () => {
  it('muestra indicadores gerenciales y permite expandir un turno', () => {
    render(
      <CashRegisterCommandCenter
        branches={[{ id: 1, name: 'Centro', code: 'CTR' }]}
        registers={[{ id: 1, name: 'Caja Principal', code: 'C1', branch_id: 1 }]}
      />,
    );

    expect(screen.getByText('Centro de control')).toBeInTheDocument();
    expect(screen.getByText('1 requieren atención')).toBeInTheDocument();
    expect(screen.getByText('Caja Principal')).toBeInTheDocument();
    expect(screen.getAllByText('$-2,00')).toHaveLength(2);

    fireEvent.click(screen.getByRole('button', { name: /Caja Principal/ }));

    expect(screen.getByText('Resumen del turno #7')).toBeInTheDocument();
    expect(screen.getByText('Efectivo USD · 1')).toBeInTheDocument();
    expect(screen.getByText('pos_payment · cash')).toBeInTheDocument();
  });
});
