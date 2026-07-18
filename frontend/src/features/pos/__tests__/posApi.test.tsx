import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetPaginated = vi.fn();
const mockPostOne = vi.fn();

vi.mock('@/api/client', () => ({
  getMany: vi.fn(),
  getPaginated: (path: string) => mockGetPaginated(path),
  patchOne: vi.fn(),
  postOne: (path: string, body: unknown) => mockPostOne(path, body),
}));

import { useBranchesForPos, useCreateCashRegister } from '../api';

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('pos api', () => {
  beforeEach(() => {
    mockGetPaginated.mockReset();
    mockPostOne.mockReset();
  });

  it('lee sucursales desde respuesta paginada', async () => {
    mockGetPaginated.mockResolvedValue({
      data: [{ id: 1, code: 'MAIN', name: 'Principal', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
    });

    const { result } = renderHook(() => useBranchesForPos(), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockGetPaginated).toHaveBeenCalledWith('/branches?per_page=100');
    expect(result.current.data?.[0]?.name).toBe('Principal');
  });

  it('crea caja fisica en el endpoint de cash register', async () => {
    mockPostOne.mockResolvedValue({ id: 5, name: 'Caja 1', code: 'CJ1', branch_id: 1 });

    const { result } = renderHook(() => useCreateCashRegister(), { wrapper });
    result.current.mutate({ branch_id: 1, name: 'Caja 1', code: 'CJ1', status: 'active' });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockPostOne).toHaveBeenCalledWith('/cash-register/registers', {
      branch_id: 1,
      name: 'Caja 1',
      code: 'CJ1',
      status: 'active',
    });
  });
});
