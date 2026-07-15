/**
 * API del modulo de Traslados (InventoryTransfers intra-tenant).
 * Cubre los endpoints:
 *   - GET    /api/inventory-transfers
 *   - POST   /api/inventory-transfers
 *   - GET    /api/inventory-transfers/{id}
 *   - POST   /api/inventory-transfers/{id}/prepare
 *   - POST   /api/inventory-transfers/{id}/dispatch
 *   - POST   /api/inventory-transfers/{id}/receive
 *   - POST   /api/inventory-transfers/{id}/cancel
 *   - POST   /api/inventory-transfers/{id}/resolve-differences
 *   - PUT    /api/inventory-transfers/{id}/driver
 *   - DELETE /api/inventory-transfers/{id}/driver
 *   - GET    /api/inventory-transfers/{id}/checklist/{stage}
 *   - POST   /api/inventory-transfers/{id}/checklist/{stage}/items/{itemId}/check
 *   - GET    /api/inventory-transfers/{id}/guide.pdf
 *   - GET    /api/inventory-transfers/{id}/guide.html
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { deleteOne, getMany, getOne, postOne, putOne } from '@/api/client';
import { productKeys } from '@/features/inventory-center/queries';
import {
  ChecklistPayloadSchema,
  TransferDriverSchema,
  TransferSchema,
  type CancelTransferValues,
  type PrepareTransferValues,
  type ReceiveTransferValues,
  type StoreTransferValues,
  type AssignDriverValues,
  type CheckChecklistItemValues,
  type Transfer,
  type TransferDriver,
  type TransferListFilters,
} from './schemas';

export const transferKeys = {
  all: ['transfers'] as const,
  lists: () => [...transferKeys.all, 'list'] as const,
  list: (filters: Record<string, unknown>) => [...transferKeys.lists(), filters] as const,
  details: () => [...transferKeys.all, 'detail'] as const,
  detail: (id: number) => [...transferKeys.details(), id] as const,
  checklists: () => [...transferKeys.all, 'checklist'] as const,
  checklist: (id: number, stage: string) => [...transferKeys.checklists(), id, stage] as const,
};

function toQueryString(filters: Partial<TransferListFilters>): string {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v == null || v === '' || v === 'all') continue;
    params.set(k, String(v));
  }
  const q = params.toString();
  return q ? `?${q}` : '';
}

export function useTransfers(filters: Partial<TransferListFilters> = {}) {
  return useQuery({
    queryKey: transferKeys.list(filters as Record<string, unknown>),
    queryFn: async () => {
      const data = await getMany<unknown>(`/inventory-transfers${toQueryString(filters)}`);
      const arr = Array.isArray(data) ? data : ((data as { data?: unknown[] })?.data ?? []);
      const parsed = (await import('zod')).z.array(TransferSchema).safeParse(arr);
      if (!parsed.success) {
        console.warn('useTransfers: shape invalido', parsed.error.flatten());
        return [];
      }
      return parsed.data;
    },
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  });
}

export function useTransfer(id: number) {
  return useQuery({
    queryKey: transferKeys.detail(id),
    queryFn: async () => {
      const data = await getOne<{ data: unknown }>(`/inventory-transfers/${id}`);
      return TransferSchema.parse(data.data ?? data);
    },
    enabled: Number.isFinite(id) && id > 0,
  });
}

export function useCreateTransfer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (values: StoreTransferValues) =>
      postOne<StoreTransferValues, Transfer>('/inventory-transfers', values),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: transferKeys.lists() });
      await qc.refetchQueries({ queryKey: transferKeys.lists() });
    },
  });
}

export function usePrepareTransfer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: PrepareTransferValues }) =>
      postOne<PrepareTransferValues, Transfer>(`/inventory-transfers/${id}/prepare`, values),
    onSuccess: (_data, { id }) => {
      void qc.invalidateQueries({ queryKey: transferKeys.lists() });
      void qc.invalidateQueries({ queryKey: transferKeys.detail(id) });
      void qc.invalidateQueries({ queryKey: transferKeys.checklists() });
    },
  });
}

export function useDispatchTransfer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: { dispatched_at?: string; notes?: string } }) =>
      postOne(`/inventory-transfers/${id}/dispatch`, values),
    onSuccess: (_data, { id }) => {
      void qc.invalidateQueries({ queryKey: transferKeys.lists() });
      void qc.invalidateQueries({ queryKey: transferKeys.detail(id) });
    },
  });
}

export function useReceiveTransfer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: ReceiveTransferValues }) =>
      postOne<ReceiveTransferValues, Transfer>(`/inventory-transfers/${id}/receive`, values),
    onSuccess: (_data, { id }) => {
      void qc.invalidateQueries({ queryKey: transferKeys.lists() });
      void qc.invalidateQueries({ queryKey: transferKeys.detail(id) });
      void qc.invalidateQueries({ queryKey: transferKeys.checklists() });
      // Invalidar productos afectados (movimiento de stock, similar a
      // Purchases: ver helper en features/purchases/api.ts).
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
      void qc.invalidateQueries({ queryKey: productKeys.all });
    },
  });
}

export function useCancelTransfer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: CancelTransferValues }) =>
      postOne(`/inventory-transfers/${id}/cancel`, values),
    onSuccess: (_data, { id }) => {
      void qc.invalidateQueries({ queryKey: transferKeys.lists() });
      void qc.invalidateQueries({ queryKey: transferKeys.detail(id) });
    },
  });
}

export function useResolveTransferDifferences() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: { items: unknown[]; notes?: string } }) =>
      postOne(`/inventory-transfers/${id}/resolve-differences`, values),
    onSuccess: (_data, { id }) => {
      void qc.invalidateQueries({ queryKey: transferKeys.lists() });
      void qc.invalidateQueries({ queryKey: transferKeys.detail(id) });
    },
  });
}

export function useAssignDriver() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: AssignDriverValues }) =>
      putOne<AssignDriverValues, TransferDriver>(`/inventory-transfers/${id}/driver`, values),
    onSuccess: (_data, { id }) => {
      void qc.invalidateQueries({ queryKey: transferKeys.detail(id) });
    },
  });
}

export function useRemoveDriver() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/inventory-transfers/${id}/driver`),
    onSuccess: (_data, id) => {
      void qc.invalidateQueries({ queryKey: transferKeys.detail(id) });
    },
  });
}

export function useChecklist(transferId: number, stage: 'preparation' | 'reception') {
  return useQuery({
    queryKey: transferKeys.checklist(transferId, stage),
    queryFn: async () => {
      const data = await getOne<{ data: unknown }>(
        `/inventory-transfers/${transferId}/checklist/${stage}`,
      );
      return ChecklistPayloadSchema.parse(data.data ?? data);
    },
    enabled: Number.isFinite(transferId) && transferId > 0,
  });
}

export function useCheckChecklistItem() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({
      transferId,
      stage,
      itemId,
      values,
    }: {
      transferId: number;
      stage: 'preparation' | 'reception';
      itemId: number;
      values: CheckChecklistItemValues;
    }) =>
      postOne<CheckChecklistItemValues, { data: unknown }>(
        `/inventory-transfers/${transferId}/checklist/${stage}/items/${itemId}/check`,
        values,
      ),
    onSuccess: (_data, { transferId, stage }) => {
      void qc.invalidateQueries({ queryKey: transferKeys.checklist(transferId, stage) });
      void qc.invalidateQueries({ queryKey: transferKeys.detail(transferId) });
    },
  });
}

export type { TransferListFilters } from './schemas';

export function useTransferDriver(transferId: number) {
  return useQuery({
    queryKey: [...transferKeys.detail(transferId), 'driver'] as const,
    queryFn: async () => {
      const data = await getOne<{ data: unknown }>(`/inventory-transfers/${transferId}`);
      const transfer = TransferSchema.parse(data.data ?? data);
      if (!transfer.driver) return null;
      return TransferDriverSchema.parse(transfer.driver);
    },
    enabled: Number.isFinite(transferId) && transferId > 0,
  });
}

// =====================================================================
// Lookups reutilizados por el dialog de crear traslado.
// Reusamos los hooks de inventory-center (warehouses, products) que ya
// existen. Solo re-exportamos para que el codigo del modulo de transfers
// no tenga que importar del modulo de inventory.
// =====================================================================

export { useWarehouses } from '@/features/inventory-center/api';

import {
  ProductSchema,
} from '@/features/inventory-center/schemas';

/**
 * Lista de productos activos (max 100) para usar en el dialog de
 * crear traslado (autocomplete de productos). Re-exporta el schema
 * Product del modulo de inventory-center.
 */
export function useProductsForTransfer() {
  return useQuery({
    queryKey: [...productKeys.lists(), 'for-transfer'] as const,
    queryFn: async () => {
      const data = await getMany<unknown>('/products?per_page=100');
      const arr = Array.isArray(data) ? data : ((data as { data?: unknown[] })?.data ?? []);
      return (await import('zod')).z.array(ProductSchema).parse(arr);
    },
    staleTime: 5 * 60 * 1000,
  });
}
