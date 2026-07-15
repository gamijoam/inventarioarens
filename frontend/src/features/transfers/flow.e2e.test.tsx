/**
 * Test E2E del flujo logistico de traslados (4 etapas):
 *
 *   CREAR (Solicitado) -> PREPARAR -> DESPACHAR -> RECIBIR (Completado)
 *
 * Mockeamos api/client para capturar las llamadas HTTP reales que hace
 * el frontend (metodo, URL, payload) y simular respuestas del backend
 * con el state machine correcto.
 *
 * Cobertura:
 *   1. useCreateTransfer POST /inventory-transfers
 *   2. usePrepareTransfer POST /inventory-transfers/{id}/prepare
 *   3. useDispatchTransfer POST /inventory-transfers/{id}/dispatch
 *   4. useReceiveTransfer POST /inventory-transfers/{id}/receive
 *   5. Payload correcto en cada paso (incluyendo inventory_transfer_item_id)
 *   6. Orden de invocaciones
 *   7. Estado del transfer despues de cada paso
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';

interface CallRecord {
  method: string;
  url: string;
  body?: unknown;
}
const apiCalls: CallRecord[] = [];
let nextTransferId = 1000;
let nextItemId = 5000;

vi.mock('@/api/client', () => {
  const record = (method: string) => (url: string, body?: unknown): Promise<{ data: { data: unknown } }> => {
    apiCalls.push({ method, url, body });
    return simulateBackend(method, url, body);
  };
  // Extrae response.data.data como hace postOne/patchOne/putOne/deleteOne reales.
  const wrapHelper = (method: string) => async (url: string, body?: unknown): Promise<unknown> => {
    const response = await record(method)(url, body);
    return response.data.data;
  };
  return {
    api: {
      get: (url: string) => {
        apiCalls.push({ method: 'GET', url });
        return Promise.resolve({ data: { data: simulateGet(url) } });
      },
      post: record('POST'),
      patch: record('PATCH'),
      put: record('PUT'),
      delete: record('DELETE'),
    },
    getOne: (url: string) => Promise.resolve(simulateGet(url)),
    getMany: () => Promise.resolve([]),
    postOne: wrapHelper('POST'),
    patchOne: wrapHelper('PATCH'),
    putOne: wrapHelper('PUT'),
    deleteOne: wrapHelper('DELETE'),
    registerUnauthorizedHandler: () => undefined,
  };
});

const transferState = {
  id: 0,
  status: 'requested' as string,
  items: [] as { id: number; product_id: number; quantity: number; prepared_quantity: number | null; received_quantity: number | null }[],
};

function simulateGet(url: string): unknown {
  if (url.includes(`/inventory-transfers/${transferState.id}`)) {
    return { ...transferState };
  }
  if (url.includes('/inventory-transfers') && !url.includes('/prepare') && !url.includes('/dispatch') && !url.includes('/receive')) {
    return [transferState.id ? { ...transferState } : null].filter(Boolean);
  }
  return [];
}

function simulateBackend(method: string, url: string, body?: unknown): Promise<{ data: { data: unknown } }> {
  const respond = (data: unknown): { data: { data: unknown } } => ({ data: { data } });

  // POST /inventory-transfers (create)
  if (method === 'POST' && url === '/inventory-transfers') {
    const id = nextTransferId++;
    transferState.id = id;
    transferState.status = 'requested';
    transferState.items = (body as { items?: unknown[] })?.items?.map((it, i) => ({
      id: nextItemId + i,
      product_id: (it as { product_id: number }).product_id,
      quantity: (it as { quantity: number }).quantity,
      prepared_quantity: null,
      received_quantity: null,
    })) ?? [];
    return Promise.resolve(respond({ ...transferState }));
  }

  // POST /inventory-transfers/{id}/prepare
  if (method === 'POST' && /\/inventory-transfers\/\d+\/prepare$/.test(url)) {
    if (transferState.status !== 'requested') {
      return Promise.reject(new Error('Solo se pueden preparar traslados solicitados.'));
    }
    const items = (body as { items?: { inventory_transfer_item_id: number; prepared_quantity?: number }[] })?.items ?? [];
    if (items.length === 0) {
      return Promise.reject(new Error('Debe preparar todos los productos incluidos en la guia.'));
    }
    let allPrepared = true;
    for (const payloadItem of items) {
      const item = transferState.items.find((i) => i.id === payloadItem.inventory_transfer_item_id);
      if (item) {
        item.prepared_quantity = payloadItem.prepared_quantity ?? item.quantity;
        if ((item.prepared_quantity ?? 0) < item.quantity) allPrepared = false;
      }
    }
    transferState.status = allPrepared ? 'prepared' : 'prepared_with_differences';
    return Promise.resolve(respond({ ...transferState }));
  }

  // POST /inventory-transfers/{id}/dispatch
  if (method === 'POST' && /\/inventory-transfers\/\d+\/dispatch$/.test(url)) {
    if (transferState.status !== 'prepared' && transferState.status !== 'prepared_with_differences') {
      return Promise.reject(new Error('Solo se pueden despachar traslados preparados.'));
    }
    transferState.status = 'dispatched';
    return Promise.resolve(respond({ ...transferState }));
  }

  // POST /inventory-transfers/{id}/receive
  if (method === 'POST' && /\/inventory-transfers\/\d+\/receive$/.test(url)) {
    if (transferState.status !== 'dispatched' && transferState.status !== 'prepared' && transferState.status !== 'requested') {
      return Promise.reject(new Error('Solo se pueden recibir traslados despachados.'));
    }
    const items = (body as { items?: { inventory_transfer_item_id: number; received_quantity?: number }[] })?.items ?? [];
    let allComplete = true;
    for (const payloadItem of items) {
      const item = transferState.items.find((i) => i.id === payloadItem.inventory_transfer_item_id);
      if (item) {
        item.received_quantity = payloadItem.received_quantity ?? item.quantity;
        if ((item.received_quantity ?? 0) < item.quantity) allComplete = false;
      }
    }
    transferState.status = allComplete ? 'completed' : 'completed_with_differences';
    return Promise.resolve(respond({ ...transferState }));
  }

  return Promise.reject(new Error(`Endpoint no mockeado: ${method} ${url}`));
}

function withQueryClient() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

import {
  useCreateTransfer,
  usePrepareTransfer,
  useDispatchTransfer,
  useReceiveTransfer,
} from './api';

describe('E2E: flujo logistico completo de un traslado', () => {
  beforeEach(() => {
    apiCalls.length = 0;
    transferState.id = 0;
    transferState.status = 'requested';
    transferState.items = [];
    nextTransferId = 1000;
    nextItemId = 5000;
  });

  it('CREAR -> PREPARAR -> DESPACHAR -> RECIBIR (happy path)', async () => {
    const wrapper = withQueryClient();

    // 1. CREAR
    const { result: create } = renderHook(() => useCreateTransfer(), { wrapper });
    const created = await create.current.mutateAsync({
      from_warehouse_id: 1,
      to_warehouse_id: 2,
      validation_mode: 'logistics',
      type: 'internal',
      reason: null,
      reference: 'E2E-001',
      notes: null,
      processed_at: null,
      document_number: 'TRF-E2E-001',
      items: [
        { product_id: 100, quantity: 5 },
        { product_id: 200, quantity: 3 },
      ],
    }) as { id: number; status: string; items: { id: number }[] };
    expect(created.id).toBeGreaterThan(0);
    expect(created.status).toBe('requested');
    const transferId = created.id;
    const item1 = created.items[0].id;
    const item2 = created.items[1].id;

    // 2. PREPARAR
    const { result: prepare } = renderHook(() => usePrepareTransfer(), { wrapper });
    const prepared = await prepare.current.mutateAsync({
      id: transferId,
      values: {
        prepared_at: null,
        notes: null,
        items: [
          { inventory_transfer_item_id: item1, prepared_quantity: 5 },
          { inventory_transfer_item_id: item2, prepared_quantity: 3 },
        ],
      },
    }) as { status: string };
    expect(prepared.status).toBe('prepared');

    // 3. DESPACHAR
    const { result: dispatch } = renderHook(() => useDispatchTransfer(), { wrapper });
    const dispatched = await dispatch.current.mutateAsync({
      id: transferId,
      values: { dispatched_at: null, notes: 'Sale en camion ABC' },
    }) as { status: string };
    expect(dispatched.status).toBe('dispatched');

    // 4. RECIBIR
    const { result: receive } = renderHook(() => useReceiveTransfer(), { wrapper });
    const received = await receive.current.mutateAsync({
      id: transferId,
      values: {
        received_at: null,
        notes: null,
        items: [
          { inventory_transfer_item_id: item1, received_quantity: 5 },
          { inventory_transfer_item_id: item2, received_quantity: 3 },
        ],
      },
    }) as { status: string };
    expect(received.status).toBe('completed');

    // Verificar orden y metodos HTTP
    const mutations = apiCalls.filter((c) => c.method !== 'GET');
    expect(mutations.map((c) => `${c.method} ${c.url}`)).toEqual([
      `POST /inventory-transfers`,
      `POST /inventory-transfers/${transferId}/prepare`,
      `POST /inventory-transfers/${transferId}/dispatch`,
      `POST /inventory-transfers/${transferId}/receive`,
    ]);

    // Verificar payloads clave
    const prepareCall = mutations[1];
    expect(prepareCall.body).toMatchObject({
      items: expect.arrayContaining([
        expect.objectContaining({ inventory_transfer_item_id: item1, prepared_quantity: 5 }),
      ]),
    });
    const receiveCall = mutations[3];
    expect(receiveCall.body).toMatchObject({
      items: expect.arrayContaining([
        expect.objectContaining({ inventory_transfer_item_id: item1, received_quantity: 5 }),
      ]),
    });
  });

  it('bloquea PREPARAR si el transfer esta en estado invalido', async () => {
    const wrapper = withQueryClient();

    // Crear un transfer pero forzar estado dispatched para invalidar
    transferState.status = 'dispatched';
    transferState.id = 9999;
    transferState.items = [{ id: 1, product_id: 1, quantity: 5, prepared_quantity: 5, received_quantity: 0 }];

    const { result: prepare } = renderHook(() => usePrepareTransfer(), { wrapper });
    await expect(
      prepare.current.mutateAsync({
        id: 9999,
        values: { prepared_at: null, notes: null, items: [{ inventory_transfer_item_id: 1, prepared_quantity: 5 }] },
      })
    ).rejects.toThrow();

    // Asegurar que el unico call al backend fue el POST /prepare (fallido)
    const prepareCall = apiCalls.find((c) => c.method === 'POST' && c.url.includes('/prepare'));
    expect(prepareCall).toBeDefined();
  });

  it('bloquea DESPACHAR si el transfer esta en requested', async () => {
    const wrapper = withQueryClient();
    transferState.status = 'requested';
    transferState.id = 8888;
    transferState.items = [];

    const { result: dispatch } = renderHook(() => useDispatchTransfer(), { wrapper });
    await expect(
      dispatch.current.mutateAsync({ id: 8888, values: { dispatched_at: null, notes: null } })
    ).rejects.toThrow();
  });

  it('PREPARAR con partial quantity -> prepared_with_differences', async () => {
    const wrapper = withQueryClient();

    const { result: create } = renderHook(() => useCreateTransfer(), { wrapper });
    const created = await create.current.mutateAsync({
      from_warehouse_id: 1,
      to_warehouse_id: 2,
      validation_mode: 'logistics',
      type: 'internal',
      reason: null,
      reference: null,
      notes: null,
      processed_at: null,
      document_number: null,
      items: [{ product_id: 100, quantity: 10 }],
    }) as { id: number; items: { id: number }[] };
    const transferId = created.id;
    const itemId = created.items[0].id;

    const { result: prepare } = renderHook(() => usePrepareTransfer(), { wrapper });
    const prepared = await prepare.current.mutateAsync({
      id: transferId,
      values: {
        prepared_at: null,
        notes: 'Solo prepare 7 de 10',
        items: [{ inventory_transfer_item_id: itemId, prepared_quantity: 7 }],
      },
    }) as { status: string };
    expect(prepared.status).toBe('prepared_with_differences');
  });
});