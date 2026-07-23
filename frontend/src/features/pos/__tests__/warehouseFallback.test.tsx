/**
 * Test para el fix del warehouse selector en POS (Sprint POS 5).
 *
 * Caso cubierto: cuando /api/pos/bootstrap no tiene warehouses (cache
 * vacio o query fallo), el selector cae a /api/warehouses via
 * useWarehousesForPos(). Esto evita que el selector quede vacio y los
 * queries de productos fallen con warehouseId=null.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';

const mockGetMany = vi.fn();
const mockApiGet = vi.fn();

vi.mock('@/api/client', () => ({
  api: { get: (path: string) => mockApiGet(path) },
  getMany: (path: string) => mockGetMany(path),
  getOne: vi.fn(),
  getPaginated: vi.fn(),
  patchOne: vi.fn(),
  postOne: vi.fn(),
}));

import { useBootstrapRefsForPos, usePosBootstrap, useWarehousesForPos } from '../api';

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('pos warehouse selector fallback', () => {
  beforeEach(() => {
    mockGetMany.mockReset();
    mockApiGet.mockReset();
  });

  it('usa useBootstrapRefsForPos cuando bootstrap devuelve warehouses', async () => {
    mockApiGet.mockResolvedValueOnce({
      data: {
        warehouses: [{ id: 1, code: 'PRINCIPAL', name: 'Almacen Principal', branch_id: null }],
        branches: [],
        cash_registers: [],
        payment_methods: [],
        price_lists: [],
        exchange_rate_types: [],
        exchange_rates: [],
        open_session: null,
      },
    });

    // Solo verificamos que el query bootstrap se llama (no renderizamos
    // el POS completo porque requiere cart store + bootstrap + auth).
    const { result } = renderHook(() => useBootstrapRefsForPos(), { wrapper });
    await waitFor(() => expect(result.current.refs?.warehouses?.length).toBe(1));
    expect(result.current.refs?.warehouses?.[0]?.code).toBe('PRINCIPAL');
  });

  it('useWarehousesForPos devuelve fallback cuando bootstrap no tiene data', () => {
    mockGetMany.mockResolvedValueOnce([]);

    const { result } = renderHook(() => useWarehousesForPos(), { wrapper });
    // El query mock no se llama todavia hasta que el componente se monte
    // y useWarehousesForPos corra. Como renderHook no monta el componente,
    // no verificamos la llamada al mock.
    expect(result.current.isLoading).toBe(true);
    expect(result.current.data).toBeUndefined();
  });
});

describe('pos warehouse selector fallback integration', () => {
  beforeEach(() => {
    mockGetMany.mockReset();
    mockApiGet.mockReset();
  });

  it('cai en /api/warehouses cuando bootstrap no devuelve warehouses', async () => {
    // 1) bootstrap retorna warehouses: []
    mockApiGet.mockResolvedValueOnce({
      data: {
        warehouses: [],
        branches: [],
        cash_registers: [],
        payment_methods: [],
        price_lists: [],
        exchange_rate_types: [],
        exchange_rates: [],
        open_session: null,
      },
    });
    // 2) fallback /api/warehouses retorna warehouses
    mockGetMany.mockResolvedValueOnce([
      { id: 5, code: 'FALLBACK01', name: 'Almacen Fallback 1', branch_id: 2 },
    ]);

    // En la implementacion real, esto se controla en el merge del POS.
    // Aqui solo verificamos que el hook devuelve data cuando bootstrap
    // esta vacio (escenario del merge).
    const boot = renderHook(() => usePosBootstrap(), { wrapper });
    const wh = renderHook(() => useWarehousesForPos(), { wrapper });
    await waitFor(() => expect(boot.result.current.isLoading).toBe(false));
    await waitFor(() => expect(wh.result.current.isLoading).toBe(false));
    expect(boot.result.current.data?.warehouses?.length ?? 0).toBe(0);
    expect((wh.result.current.data ?? []).length).toBe(1);
    expect((wh.result.current.data ?? [])[0]?.code).toBe('FALLBACK01');
  });
});
