import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockPostOne = vi.fn();
const movement = {
  id: 7,
  type: 'adjustment_out',
  reason: 'Consumo interno',
  status: 'approved',
  product: { id: 2, name: 'Producto' },
  warehouse: { id: 1, name: 'Central' },
  creator: { id: 3, name: 'Almacenista' },
  quantity: 2,
  stock_movement_id: 11,
  created_at: '2026-08-17T00:00:00Z',
};

vi.mock('@/api/client', () => ({
  createIdempotencyKey: () => `manual-test-${Math.random()}`,
  getOne: vi.fn(),
  getPaginated: vi.fn(),
  postOne: (path: string, body: unknown, config?: unknown) =>
    mockPostOne(path, body, config) as Promise<unknown>,
  withIdempotencyKey: (key: string) => ({ headers: { 'Idempotency-Key': key } }),
}));

import {
  useApproveManualMovement,
  useCreateManualMovement,
} from './api';

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}

describe('manual movements api', () => {
  beforeEach(() => {
    mockPostOne.mockReset();
    mockPostOne.mockResolvedValue(movement);
  });

  it('envia una clave de idempotencia al crear un movimiento', async () => {
    const { result } = renderHook(() => useCreateManualMovement(), { wrapper });

    result.current.mutate({
      warehouse_id: 1,
      product_id: 2,
      quantity: 2,
      type: 'adjustment_out',
      reason: 'Consumo interno',
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockPostOne).toHaveBeenCalledWith(
      '/inventory/manual-movements',
      expect.any(Object),
      expect.objectContaining({
        headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }),
      }),
    );
  });

  it('conserva la clave al reintentar la misma aprobacion', async () => {
    const { result } = renderHook(() => useApproveManualMovement(), { wrapper });
    const request = { id: 7 };

    result.current.mutate(request);
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    result.current.mutate(request);
    await waitFor(() => expect(mockPostOne).toHaveBeenCalledTimes(2));

    expect(mockPostOne.mock.calls[0]![2]).toEqual(mockPostOne.mock.calls[1]![2]);
  });
});
