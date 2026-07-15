/**
 * Test de regresion: verifica que los 5 endpoints de acciones de un
 * traslado (prepare, dispatch, receive, cancel, resolve-differences)
 * usan POST. Esto evita el bug clasico donde se envia PATCH y el
 * backend solo soporta POST (MethodNotAllowed).
 *
 * Mockeamos `api/client` para capturar el method HTTP de cada request.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

const apiCalls: { method: string; url: string }[] = [];

vi.mock('@/api/client', () => ({
  api: {
    get: (url: string) => {
      apiCalls.push({ method: 'GET', url });
      return Promise.resolve({ data: { data: [] } });
    },
    post: (url: string) => {
      apiCalls.push({ method: 'POST', url });
      return Promise.resolve({ data: { data: { id: 1 } } });
    },
    patch: (url: string) => {
      apiCalls.push({ method: 'PATCH', url });
      return Promise.resolve({ data: { data: { id: 1 } } });
    },
    put: (url: string) => {
      apiCalls.push({ method: 'PUT', url });
      return Promise.resolve({ data: { data: { id: 1 } } });
    },
    delete: (url: string) => {
      apiCalls.push({ method: 'DELETE', url });
      return Promise.resolve({ data: undefined });
    },
  },
  getOne: () => Promise.resolve([] as unknown),
  getMany: () => Promise.resolve([]),
  postOne: (url: string) => {
    apiCalls.push({ method: 'POST', url });
    return Promise.resolve({ id: 1 });
  },
  patchOne: (url: string) => {
    apiCalls.push({ method: 'PATCH', url });
    return Promise.resolve({ id: 1 });
  },
  putOne: (url: string) => {
    apiCalls.push({ method: 'PUT', url });
    return Promise.resolve({ id: 1 });
  },
  deleteOne: (url: string) => {
    apiCalls.push({ method: 'DELETE', url });
    return Promise.resolve();
  },
  registerUnauthorizedHandler: () => undefined,
}));

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import {
  usePrepareTransfer,
  useDispatchTransfer,
  useReceiveTransfer,
  useCancelTransfer,
  useResolveTransferDifferences,
  useAssignDriver,
  useRemoveDriver,
} from './api';

function withQueryClient() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('HTTP methods de los hooks de traslados', () => {
  beforeEach(() => {
    apiCalls.length = 0;
  });

  it('usePrepareTransfer usa POST /prepare', async () => {
    const { result } = renderHook(() => usePrepareTransfer(), { wrapper: withQueryClient() });
    await result.current.mutateAsync({ id: 5, values: { prepared_at: null, notes: null, items: [{ inventory_transfer_item_id: 1, prepared_quantity: 1 }] } });
    const call = apiCalls.find((c) => c.url.includes('/prepare'));
    expect(call).toBeDefined();
    expect(call!.method).toBe('POST');
  });

  it('useDispatchTransfer usa POST /dispatch', async () => {
    const { result } = renderHook(() => useDispatchTransfer(), { wrapper: withQueryClient() });
    await result.current.mutateAsync({ id: 5, values: { dispatched_at: null, notes: null } });
    const call = apiCalls.find((c) => c.url.includes('/dispatch'));
    expect(call).toBeDefined();
    expect(call!.method).toBe('POST');
  });

  it('useReceiveTransfer usa POST /receive', async () => {
    const { result } = renderHook(() => useReceiveTransfer(), { wrapper: withQueryClient() });
    await result.current.mutateAsync({ id: 5, values: { received_at: null, notes: null, items: [{ inventory_transfer_item_id: 1, received_quantity: 1 }] } });
    const call = apiCalls.find((c) => c.url.includes('/receive'));
    expect(call).toBeDefined();
    expect(call!.method).toBe('POST');
  });

  it('useCancelTransfer usa POST /cancel', async () => {
    const { result } = renderHook(() => useCancelTransfer(), { wrapper: withQueryClient() });
    await result.current.mutateAsync({ id: 5, values: { cancelled_at: null, cancellation_reason: 'Test reason' } });
    const call = apiCalls.find((c) => c.url.includes('/cancel'));
    expect(call).toBeDefined();
    expect(call!.method).toBe('POST');
  });

  it('useResolveTransferDifferences usa POST /resolve-differences', async () => {
    const { result } = renderHook(() => useResolveTransferDifferences(), { wrapper: withQueryClient() });
    await result.current.mutateAsync({ id: 5, values: { items: [], notes: null } });
    const call = apiCalls.find((c) => c.url.includes('/resolve-differences'));
    expect(call).toBeDefined();
    expect(call!.method).toBe('POST');
  });

  it('useAssignDriver usa PUT /driver (backend define PUT)', async () => {
    const { result } = renderHook(() => useAssignDriver(), { wrapper: withQueryClient() });
    await result.current.mutateAsync({ id: 5, values: { name: 'Test', document_number: null, phone: null, vehicle_plate: null, carrier_company: null } });
    const call = apiCalls.find((c) => c.url.includes('/driver'));
    expect(call).toBeDefined();
    expect(call!.method).toBe('PUT');
  });

  it('useRemoveDriver usa DELETE /driver', async () => {
    const { result } = renderHook(() => useRemoveDriver(), { wrapper: withQueryClient() });
    await result.current.mutateAsync(5);
    const call = apiCalls.find((c) => c.url.includes('/driver'));
    expect(call).toBeDefined();
    expect(call!.method).toBe('DELETE');
  });
});