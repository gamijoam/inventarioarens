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
  // Contador del badge en el sidebar: solicitudes pendientes donde el
  // tenant actual es el destino. Su queryKey incluye el tenant porque
  // el contador depende del contexto (cada spinoff ve solo sus propias
  // pendientes). Por eso las mutaciones invalidan `unreadCounts` (todas
  // las claves que empiezan con `unread-count`) para refrescar el badge
  // del tenant actual sin importar el id especifico.
  unreadCounts: () => [...transferRequestKeys.all, 'unread-count'] as const,
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
  const query = useQuery({
    queryKey: transferRequestKeys.detail(id),
    queryFn: async () => {
      const data = await getOne<{ data: unknown }>(`/inventory-transfer-requests/${id}`);
      return TransferRequestSchema.parse(data.data ?? data);
    },
    enabled: Number.isFinite(id) && id > 0,
  });

  // Devolvemos forma aplanada para evitar `queryResult.data?.id` en cada callsite.
  return {
    data: query.data,
    isLoading: query.isLoading,
    isError: query.isError,
  };
}

export function useCreateTransferRequest() {
  const qc = useQueryClient();
  return useMutation<TransferRequest, Error, StoreTransferRequestValues>({
    mutationFn: async (values) =>
      postOne<StoreTransferRequestValues, TransferRequest>('/inventory-transfer-requests', values),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: transferRequestKeys.lists() });
      await qc.refetchQueries({ queryKey: transferRequestKeys.lists() });
      // El destinatario debe ver el badge incrementado al instante:
      // invalidamos TODOS los contadores (unread-count) por si la cache
      // actual ya conto la solicitud vieja.
      void qc.invalidateQueries({ queryKey: transferRequestKeys.unreadCounts() });
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
      // El status pasa de `requested` a `accepted`/`dispatched`, asi que
      // el contador del destinatario debe bajar al instante.
      void qc.invalidateQueries({ queryKey: transferRequestKeys.unreadCounts() });
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
      // Mismo motivo que accept: el contador del destinatario baja.
      void qc.invalidateQueries({ queryKey: transferRequestKeys.unreadCounts() });
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
      // Si el origin cancela, el destinatario tambien debe enterarse.
      void qc.invalidateQueries({ queryKey: transferRequestKeys.unreadCounts() });
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
 * Sidebar para mostrar un badge con el contador.
 *
 * Implementacion: hace un GET a la lista con filtros {status: 'requested'}
 * y descuenta las que el usuario ya vio (su `lastSeenAt` persistido en
 * localStorage). De esta forma, cuando el usuario abre la pagina de
 * Solicitudes inter-empresa, las solicitudes se marcan como "vistas"
 * y el badge se limpia para reflejar "hay N sin responder" en
 * lugar de "llegaron N despues de la ultima vez que mire".
 *
 * Persistencia en localStorage (clave por tenant) en lugar de backend
 * para no requerir una migracion nueva ni un endpoint extra. Esto es
 * una aproximacion pragmatica: si el user abre la app en otro navegador
 * o en incognito, el contador se reinicia. Aceptable para v1.
 */
export function useUnreadTransferRequestsCount(
  options: UseUnreadCountOptions = {},
) {
  const { currentTenantId, refetchInterval = 30000 } = options;
  return useQuery({
    queryKey: [...transferRequestKeys.all, 'unread-count', currentTenantId] as const,
    enabled: typeof currentTenantId === 'number' && currentTenantId > 0,
    queryFn: async () => {
      // Traemos la lista completa (no el meta.total) porque necesitamos
      // el `requested_at` de cada solicitud para filtrar por lastSeenAt.
      // per_page=1 nos da solo el conteo via meta, pero per_page max
      // del listado. Usamos per_page alto (200) para cubrir todos los
      // pendientes en un solo request.
      const raw = (await getMany<unknown>(
        `/inventory-transfer-requests?status=requested&per_page=200`,
      )) as { data?: Array<{ requested_at?: string }> } | Array<unknown>;

      let items: Array<{ requested_at?: string }> = [];
      if (Array.isArray(raw)) {
        items = raw as Array<{ requested_at?: string }>;
      } else if (raw && typeof raw === 'object' && Array.isArray(raw.data)) {
        items = raw.data;
      }

      const lastSeenIso = currentTenantId !== undefined ? readLastSeenFor(currentTenantId) : null;
      if (!lastSeenIso) {
        return items.length;
      }
      const lastSeen = new Date(lastSeenIso).getTime();
      const unseen = items.filter((item) => {
        if (!item.requested_at) return true;
        return new Date(item.requested_at).getTime() > lastSeen;
      });
      return unseen.length;
    },
    refetchInterval,
    staleTime: 0,
    refetchOnWindowFocus: true,
  });
}

/**
 * Persistencia local (localStorage) de "ultima vez que vi las solicitudes
 * pendientes" por tenant. La clave es estable entre paginaciones: no se
 * borra al cerrar el navegador, solo cuando el user hace "clear site data".
 */
function lastSeenKey(tenantId: number): string {
  return `itr.lastSeenAt.tenant.${tenantId}`;
}

function readLastSeenFor(tenantId: number): string | null {
  if (typeof window === 'undefined') return null;
  try {
    return window.localStorage.getItem(lastSeenKey(tenantId));
  } catch {
    return null;
  }
}

/**
 * Marca las solicitudes pendientes como "vistas" para el tenant actual.
 * Llamar desde la pagina `/inventory-transfer-requests` cuando el user
 * entra (no al recibir el push, sino al visitar la lista).
 */
export function markTransferRequestsAsSeen(tenantId: number): void {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(lastSeenKey(tenantId), new Date().toISOString());
  } catch {
    // localStorage puede estar lleno o deshabilitado. No es critico:
    // el badge seguira mostrando el conteo real hasta el proximo
    // re-fetch y eventualmente se limpiara cuando el user recargue.
  }
}

/**
 * Helper para invalidar el badge de TODOS los tenants a la vez (usado
 * por mutaciones que afectan al destinatario, no al actor). Como la
 * queryKey del badge incluye el tenant id, invalidar la "familia"
 * `unreadCounts` es mas robusto que adivinar ids especificos.
 */
export function invalidateUnreadCounts(qc: import('@tanstack/react-query').QueryClient): void {
  void qc.invalidateQueries({ queryKey: transferRequestKeys.unreadCounts() });
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
