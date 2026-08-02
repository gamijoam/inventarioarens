import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useProductsForPurchase } from './api';

const { mockGetMany } = vi.hoisted(() => ({ mockGetMany: vi.fn() }));

vi.mock('@/api/client', () => ({
  getMany: mockGetMany,
  getOne: vi.fn(),
  patchOne: vi.fn(),
  postOne: vi.fn(),
}));

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
  };
}

describe('useProductsForPurchase', () => {
  beforeEach(() => {
    mockGetMany.mockReset();
  });

  it('envia la busqueda al backend y conserva productos serializados', async () => {
    mockGetMany.mockResolvedValue([
      {
        id: 20,
        name: 'IPHONE 20',
        sku: 'IPHONE-20',
        barcode: null,
        tracking_type: 'serialized',
        unit_of_measure: 'unit',
        base_price: '500',
      },
    ]);

    const { result } = renderHook(() => useProductsForPurchase('IPHONE 20'), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockGetMany).toHaveBeenCalledWith(
      '/products?limit=100&tracking_type=all&search=IPHONE+20',
    );
    expect(result.current.data?.[0]).toMatchObject({
      id: 20,
      tracking_type: 'serialized',
    });
  });
});
