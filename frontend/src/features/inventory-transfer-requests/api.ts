/**
 * API del modulo InventoryTransferRequests (inter-empresa).
 * Cubre los endpoints:
 *   - GET    /api/inventory-transfer-requests
 *   - POST   /api/inventory-transfer-requests
 *   - GET    /api/inventory-transfer-requests/{id}
 *   - POST   /api/inventory-transfer-requests/{id}/accept
 *   - POST   /api/inventory-transfer-requests/{id}/reject
 *   - POST   /api/inventory-transfer-requests/{id}/cancel
 *
 * El backend aplica visibilidad por tenant (origen o destino).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { deleteOne, getMany, getOne, postOne } from '@/api/client';
import { productKeys } from '@/features/inventory-center/queries';
import {
  TransferRequestSchema,
  type AcceptTransferRequestValues,
  type RejectTransferRequestValues,
  type StoreTransferRequestValues,
  type TransferRequest,
  type TransferRequestListFilters,
} from './schemas';

export const transferRequestKeys = {
  all: ['inventory-transfer-requests'] as const,
  lists: () => [...transferRequestKeys.all, 'list'] as const,
  list: (filters: Record<string, unknown>) => [...transferRequestKeys.lists(), filters] as const,
  details: () => [...transferRequestKeys.all, 'detail'] as const,
  detail: (id: number) => [...transferRequestKeys.details(), id] as const,
};

function toQueryString(filters: Partial<TransferRequestListFilters>): string {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v == null || v === '' || v === 'all') continue;
    params.set(k, String(v));
  }
  const q = params.toString();
  return q ? `?${q}` : '';
}

export const TransferRequestListMetaSchema = z.object({
  current_page: z.number().int().min(1),
  last_page: z.number().int().min(1),
  per_page: z.number().int().min(1),
  total: z.number().int().min(0),
  from: z.number().int().nullable().optional(),
  to: z.number().int().nullable().optional(),
});
export type TransferRequestListMeta = z.infer<typeof TransferRequestListMetaSchema>;

export function useTransferRequests(filters: Partial<TransferRequestListFilters> = {}) {
  return useQuery({
    queryKey: transferRequestKeys.list(filters as Record<string, unknown>),
    queryFn: async (): Promise<{ data: TransferRequest[]; meta: TransferRequestListMeta }> => {
      const raw = (await getMany<unknown>(`/inventory-transfer-requests${toQueryString(filters)}`)) as
        | TransferRequest[]
        | { data: TransferRequest[]; meta?: Partial<TransferRequestListMeta> };
      if (Array.isArray(raw)) {
        return {
          data: raw,
          meta: { current_page: 1, last_page: 1, per_page: raw.length, total: raw.length },
        };
      }
      const arr = raw.data ?? [];
      const meta: TransferRequestListMeta = {
        current_page: raw.meta?.current_page ?? 1,
        last_page: raw.meta?.last_page ?? 1,
        per_page: raw.meta?.per_page ?? arr.length,
        total: raw.meta?.total ?? arr.length,
        from: raw.meta?.from ?? null,
        to: raw.meta?.to ?? null,
      };
      const parsed = z.array(TransferRequestSchema).safeParse(arr);
      if (!parsed.success) {
        console.warn('useTransferRequests: shape invalido', parsed.error.flatten());
        return { data: [], meta };
      }
      return { data: parsed.data, meta };
    },
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  });
}

export function useTransferRequest(id: number) {
  return useQuery({
    queryKey: transferRequestKeys.detail(id),
    queryFn: async () => {
      const data = await getOne<{ data: unknown }>(`/inventory-transfer-requests/${id}`);
      return TransferRequestSchema.parse(data.data ?? data);
    },
    enabled: Number.isFinite(id) && id > 0,
  });
}

export function useCreateTransferRequest() {
  const qc = useQueryClient();
  return useMutation<TransferRequest, Error, StoreTransferRequestValues>({
    mutationFn: async (values) =>
      postOne<StoreTransferRequestValues, TransferRequest>('/inventory-transfer-requests', values),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: transferRequestKeys.lists() });
      await qc.refetchQueries({ queryKey: transferRequestKeys.lists() });
      // Invalidar productos del origin: la solicitud no mueve stock hasta
      // ser aceptada, pero invalidamos por consistencia con otros modulos.
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
  });
}

export function useAcceptTransferRequest() {
  const qc = useQueryClient();
  return useMutation<
    TransferRequest,
    Error,
    { id: number; values: AcceptTransferRequestValues }
  >({
    mutationFn: async ({ id, values }) =>
      postOne<AcceptTransferRequestValues, TransferRequest>(
        `/inventory-transfer-requests/${id}/accept`,
        values,
      ),
    onSuccess: (_data, { id }) => {
      void qc.invalidateQueries({ queryKey: transferRequestKeys.lists() });
      void qc.invalidateQueries({ queryKey: transferRequestKeys.detail(id) });
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
      void qc.invalidateQueries({ queryKey: productKeys.all });
    },
  });
}

export function useRejectTransferRequest() {
  const qc = useQueryClient();
  return useMutation<
    TransferRequest,
    Error,
    { id: number; values: RejectTransferRequestValues }
  >({
    mutationFn: async ({ id, values }) =>
      postOne<RejectTransferRequestValues, TransferRequest>(
        `/inventory-transfer-requests/${id}/reject`,
        values,
      ),
    onSuccess: (_data, { id }) => {
      void qc.invalidateQueries({ queryKey: transferRequestKeys.lists() });
      void qc.invalidateQueries({ queryKey: transferRequestKeys.detail(id) });
    },
  });
}

export function useCancelTransferRequest() {
  const qc = useQueryClient();
  return useMutation<TransferRequest, Error, number>({
    mutationFn: async (id) =>
      postOne<Record<string, never>, TransferRequest>(
        `/inventory-transfer-requests/${id}/cancel`,
        {},
      ),
    onSuccess: (_data, id) => {
      void qc.invalidateQueries({ queryKey: transferRequestKeys.lists() });
      void qc.invalidateQueries({ queryKey: transferRequestKeys.detail(id) });
    },
  });
}

export type { TransferRequestListFilters } from './schemas';

// Re-export para callers que necesiten el schema de respuesta.
export { TransferRequestSchema } from './schemas';

// Reuso: `deleteOne` no se usa actualmente pero lo dejamos exportado para
// consistencia con otros modulos (no genera warning).
void deleteOne;
