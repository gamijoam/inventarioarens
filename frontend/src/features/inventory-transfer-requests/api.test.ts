/**
 * Tests para los hooks de inventory-transfer-requests/api.ts.
 *
 * Cubre:
 *   - useTransferRequests acepta options.refetchInterval y lo propaga a useQuery.
 *   - useTransferRequests con refetchInterval=false desactiva el polling.
 *   - useUnreadTransferRequestsCount construye el endpoint con status=requested
 *     y lee el total del meta del backend (no del array parseado).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockUseQuery = vi.fn();
const mockGetMany = vi.fn();

vi.mock('@tanstack/react-query', () => ({
  useQuery: (...args: unknown[]) => mockUseQuery(...args),
  useMutation: () => ({ mutate: vi.fn(), mutateAsync: vi.fn() }),
  useQueryClient: () => ({ invalidateQueries: vi.fn(), refetchQueries: vi.fn() }),
}));

vi.mock('@/api/client', () => ({
  getMany: (...args: unknown[]) => mockGetMany(...args),
  getOne: vi.fn(),
  postOne: vi.fn(),
  putOne: vi.fn(),
  deleteOne: vi.fn(),
}));

vi.mock('@/features/inventory-center/queries', () => ({
  productKeys: { all: ['products'], lists: () => ['products', 'list'] },
}));

import {
  useTransferRequests,
  useUnreadTransferRequestsCount,
} from './api';

describe('useTransferRequests', () => {
  beforeEach(() => {
    mockUseQuery.mockReset();
    mockUseQuery.mockReturnValue({ data: [], isLoading: false });
  });

  it('propaga refetchInterval al useQuery cuando se pasa option', () => {
    useTransferRequests({ status: 'requested' }, { refetchInterval: 5000 });
    const options = mockUseQuery.mock.calls[0]?.[0] as { refetchInterval?: number | false } | undefined;
    expect(options).toBeDefined();
    expect(options?.refetchInterval).toBe(5000);
  });

  it('desactiva el polling cuando refetchInterval=false', () => {
    useTransferRequests({ status: 'completed' }, { refetchInterval: false });
    const options = mockUseQuery.mock.calls[0]?.[0] as { refetchInterval?: number | false } | undefined;
    expect(options?.refetchInterval).toBe(false);
  });

  it('default sin options.refetchInterval es false (no polling)', () => {
    useTransferRequests({});
    const options = mockUseQuery.mock.calls[0]?.[0] as { refetchInterval?: number | false } | undefined;
    expect(options?.refetchInterval).toBe(false);
  });
});

describe('useUnreadTransferRequestsCount', () => {
  beforeEach(() => {
    mockUseQuery.mockReset();
    mockUseQuery.mockReturnValue({ data: 0 });
    mockGetMany.mockReset();
  });

  it('lee el total del meta del backend y no del array', async () => {
    // Solo el meta es relevante; el array viene vacio en el cuerpo.
    mockGetMany.mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 1, total: 7 },
    });

    // Disparamos el queryFn manualmente para verificar la logica.
    let captured: ((...args: unknown[]) => unknown) | undefined;
    mockUseQuery.mockImplementation((opts: unknown) => {
      const o = opts as { queryFn: (...args: unknown[]) => unknown };
      captured = o.queryFn;
      return { data: undefined };
    });

    useUnreadTransferRequestsCount({ currentTenantId: 5 });
    expect(captured).toBeDefined();
    const result = await (captured as (...args: unknown[]) => unknown)();
    expect(result).toBe(7);
    // Verificamos que pegó al endpoint correcto.
    expect(mockGetMany).toHaveBeenCalledWith('/inventory-transfer-requests?status=requested&per_page=1');
  });

  it('solo se activa cuando currentTenantId es positivo (no requests anonimas)', () => {
    useUnreadTransferRequestsCount({ currentTenantId: undefined });
    const options = mockUseQuery.mock.calls[0]?.[0] as { enabled?: boolean } | undefined;
    expect(options?.enabled).toBe(false);
  });

  it('se activa cuando currentTenantId es positivo', () => {
    useUnreadTransferRequestsCount({ currentTenantId: 3 });
    const options = mockUseQuery.mock.calls[0]?.[0] as { enabled?: boolean } | undefined;
    expect(options?.enabled).toBe(true);
  });

  it('respeta refetchInterval custom (ej. 60s)', () => {
    useUnreadTransferRequestsCount({ currentTenantId: 3, refetchInterval: 60000 });
    const options = mockUseQuery.mock.calls[0]?.[0] as { refetchInterval?: number } | undefined;
    expect(options?.refetchInterval).toBe(60000);
  });
});
