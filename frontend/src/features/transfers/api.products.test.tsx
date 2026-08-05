import { describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';

const { getMany } = vi.hoisted(() => ({
  getMany: vi.fn(() => Promise.resolve([])),
}));

vi.mock('@/api/client', () => ({
  getMany,
  getOne: vi.fn(),
  postOne: vi.fn(),
  putOne: vi.fn(),
  deleteOne: vi.fn(),
}));

import { useProductsForTransfer } from './api';

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

describe('useProductsForTransfer', () => {
  it('solicita la pagina completa y ambos tipos de producto', async () => {
    const { result } = renderHook(() => useProductsForTransfer(), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(getMany).toHaveBeenCalledWith('/products?limit=100&tracking_type=all');
  });

  it('filtra el catalogo en el servidor cuando se busca un producto', async () => {
    const { result } = renderHook(() => useProductsForTransfer('IPHONE 20'), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(getMany).toHaveBeenCalledWith('/products?limit=100&tracking_type=all&search=IPHONE+20');
  });
});
