import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetPaginated = vi.fn();
const mockPostOne = vi.fn();

vi.mock('@/api/client', () => ({
  getOne: vi.fn(),
  getPaginated: (path: string) => mockGetPaginated(path),
  postOne: (path: string, body: unknown) => mockPostOne(path, body),
}));

import { buildReceivablesQuery, useCollectReceivable, useReceivables } from '../api';

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('receivables api', () => {
  beforeEach(() => {
    mockGetPaginated.mockReset();
    mockPostOne.mockReset();
  });

  it('construye query de filtros de CxC', () => {
    expect(buildReceivablesQuery({
      search: 'Cliente',
      status: 'open',
      due_from: '2026-07-01',
      due_to: '2026-07-31',
      page: 2,
      limit: 25,
    })).toBe('/accounts-receivable?search=Cliente&status=open&due_from=2026-07-01&due_to=2026-07-31&page=2&limit=25');
  });

  it('lee cuentas por cobrar paginadas', async () => {
    mockGetPaginated.mockResolvedValue({
      data: [{ id: 1, sale_id: 10, status: 'pending', original_base_amount: '100.0000', balance_base_amount: '100.0000' }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });

    const { result } = renderHook(() => useReceivables({ status: 'pending', page: 1 }), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockGetPaginated).toHaveBeenCalledWith('/accounts-receivable?status=pending&page=1');
    expect(result.current.data?.data[0]?.balance_base_amount).toBe(100);
  });

  it('registra cobro con caja obligatoria', async () => {
    mockPostOne.mockResolvedValue({ id: 7, payment_currency: 'USD', amount: '25.0000', amount_base: '25.0000', amount_local: '0.0000' });

    const { result } = renderHook(() => useCollectReceivable(), { wrapper });
    result.current.mutate({
      id: 1,
      values: {
        payment_currency: 'USD',
        amount: 25,
        cash_register_session_id: 9,
        method: 'cash',
      },
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockPostOne).toHaveBeenCalledWith('/accounts-receivable/1/payments', {
      payment_currency: 'USD',
      amount: 25,
      cash_register_session_id: 9,
      method: 'cash',
    });
  });
});
