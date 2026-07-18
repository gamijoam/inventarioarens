import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetPaginated = vi.fn();
const mockPatchOne = vi.fn();

vi.mock('@/api/client', () => ({
  getOne: vi.fn(),
  getPaginated: (path: string) => mockGetPaginated(path),
  patchOne: (path: string, body: unknown) => mockPatchOne(path, body),
}));

import { buildSalesQuery, useCancelSale, useSales } from '../api';

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('sales api', () => {
  beforeEach(() => {
    mockGetPaginated.mockReset();
    mockPatchOne.mockReset();
  });

  it('construye query con filtros administrativos', () => {
    expect(buildSalesQuery({
      search: 'Cliente 123',
      status: 'confirmed',
      date_from: '2026-07-01',
      date_to: '2026-07-17',
      page: 2,
      per_page: 25,
    })).toBe('/sales?search=Cliente+123&status=confirmed&date_from=2026-07-01&date_to=2026-07-17&page=2&per_page=25');
  });

  it('lee ventas paginadas y normaliza montos', async () => {
    mockGetPaginated.mockResolvedValue({
      data: [{
        id: 10,
        status: 'confirmed',
        total_base_amount: '12.5000',
        total_local_amount: '10000.0000',
        items_count: 2,
        created_at: '2026-07-17T12:00:00.000000Z',
      }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });

    const { result } = renderHook(() => useSales({ status: 'confirmed', page: 1 }), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockGetPaginated).toHaveBeenCalledWith('/sales?status=confirmed&page=1');
    expect(result.current.data?.data[0]?.total_base_amount).toBe(12.5);
  });

  it('cancela una venta usando el endpoint del backend', async () => {
    mockPatchOne.mockResolvedValue({ id: 5, status: 'cancelled', total_base_amount: 0, total_local_amount: 0 });

    const { result } = renderHook(() => useCancelSale(), { wrapper });
    result.current.mutate(5);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockPatchOne).toHaveBeenCalledWith('/sales/5/cancel', {});
  });
});
