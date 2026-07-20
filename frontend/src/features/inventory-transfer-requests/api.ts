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

/**
 * Opciones adicionales para useTransferRequests.
 * - refetchInterval: ms entre re-fetches automaticos. false = deshabilitado
 *   (default). Usado por el Manager para activar polling en tabs Received/Pending
 *   y desactivarlo en tabs de archivo (Completed/Rejected) para ahorrar bateria.
 */
export interface UseTransferRequestsOptions {
  refetchInterval?: number | false;
}

export function useTransferRequests(
  filters: Partial<TransferRequestListFilters> = {},
  options: UseTransferRequestsOptions = {},
) {
  // Devolvemos una forma "aplanada" { data, meta, isLoading } en lugar del
  // UseQueryResult crudo, para que el caller no tenga que hacer
  // `queryResult.data?.data` (doble data). Esto es el mismo patron que
  // usa el modulo de transfers/ (ver TransfersManager).
  const query = useQuery({
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
    refetchInterval: options.refetchInterval ?? false,
  });

  const payload = query.data as { data?: TransferRequest[]; meta?: TransferRequestListMeta } | undefined;
  return {
    data: payload?.data ?? [],
    meta: payload?.meta ?? { current_page: 1, last_page: 1, per_page: 0, total: 0 },
    isLoading: query.isLoading,
  };
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

export interface UseUnreadCountOptions {
  /**
   * ID del tenant actual. Si se omite, el hook hace 0 queries (no sabe
   * a quien contar). Esto permite que el componente padre lo pase
   * condicionalmente para evitar requests anonimas.
   */
  currentTenantId?: number;
  /** Intervalo de re-fetch en ms. Default 30s. false = deshabilitado. */
  refetchInterval?: number | false;
}

/**
 * Cuenta solicitudes PENDIENTES donde el tenant actual es el destino
 * (osea solicitudes que la empresa actual debe responder). Usado por el
 * Sidebar para mostrar un badge rojo con el contador.
 *
 * Implementacion: hace un GET a la lista con filtros {status: 'requested'}
 * y cuenta los resultados del backend (no del array parseado, para no
 * descargar el payload completo).
 */
export function useUnreadTransferRequestsCount(
  options: UseUnreadCountOptions = {},
) {
  const { currentTenantId, refetchInterval = 30000 } = options;
  return useQuery({
    queryKey: [...transferRequestKeys.all, 'unread-count', currentTenantId] as const,
    enabled: typeof currentTenantId === 'number' && currentTenantId > 0,
    queryFn: async () => {
      const raw = (await getMany<unknown>(`/inventory-transfer-requests?status=requested&per_page=1`)) as
        | { meta?: { total?: number } }
        | unknown[];
      let total = 0;
      if (Array.isArray(raw)) {
        total = raw.length;
      } else if (raw && typeof raw === 'object' && 'meta' in raw) {
        total = (raw.meta && typeof raw.meta.total === 'number') ? raw.meta.total : 0;
      }
      return total;
    },
    refetchInterval,
    staleTime: 0,
    refetchOnWindowFocus: true,
  });
}

/**
 * Empresa hermana (spinoff del mismo grupo) disponible para enviarle
 * solicitudes de stock. Retornada por GET /api/tenant-groups/{id}/spinoffs.
 */
export interface SiblingCompany {
  id: number;
  name: string;
  slug: string;
  domain?: string | null;
  plan?: string | null;
  status?: string;
  users_count: number;
}

export interface UseSiblingCompaniesOptions {
  /** ID del tenant actual (necesario para excluirlo de la lista). */
  currentTenantId?: number;
  /** parent_id del tenant actual (null si soy el grupo raiz). */
  parentId?: number | null;
  /** true si el tenant actual ES el grupo raiz (entonces groupId = currentTenantId). */
  isGroup?: boolean;
}

/**
 * Devuelve las empresas hermanas del grupo al que pertenece mi tenant,
 * excluyendo mi propio tenant. Usado por el dialog de crear solicitud
 * inter-empresa para que el usuario elija visualmente a quien enviar
 * en lugar de tener que memorizar el slug.
 *
 * Resolucion del groupId:
 *   - Si is_group=true (soy el grupo raiz) -> groupId = currentTenantId.
 *   - Si is_group=false (soy spinoff) -> groupId = parentId.
 *
 * Cache 60s porque las empresas del grupo cambian muy poco.
 */
export function useSiblingCompanies(options: UseSiblingCompaniesOptions = {}) {
  const { currentTenantId, parentId, isGroup } = options;
  const groupId = isGroup ? currentTenantId : parentId;

  return useQuery({
    queryKey: ['sibling-companies', groupId, currentTenantId] as const,
    enabled: typeof groupId === 'number' && groupId > 0 && typeof currentTenantId === 'number',
    queryFn: async (): Promise<SiblingCompany[]> => {
      const raw = (await getMany<unknown>(
        `/tenant-groups/${groupId}/spinoffs`,
      )) as { data?: SiblingCompany[] } | SiblingCompany[];
      const list = Array.isArray(raw) ? raw : (raw.data ?? []);
      // Filtrar mi propio tenant (no me puedo enviar solicitudes a mi mismo).
      return list.filter((c) => c.id !== currentTenantId);
    },
    staleTime: 60_000,
  });
}

// Reuso: `deleteOne` no se usa actualmente pero lo dejamos exportado para
// consistencia con otros modulos (no genera warning).
void deleteOne;
