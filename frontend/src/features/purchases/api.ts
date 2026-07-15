/**
 * API del modulo de Compras.
 * Endpoints cubiertos:
 *   - GET    /api/purchases?search=&status=&supplier_id=&date_from=&date_to=
 *   - POST   /api/purchases                          (crear draft)
 *   - GET    /api/purchases/{id}                     (detalle)
 *   - PATCH  /api/purchases/{id}/receive             (recibir mercancia)
 *   - PATCH  /api/purchases/{id}/cancel              (cancelar draft)
 *
 * El backend NO expone PUT /api/purchases/{id} ni DELETE. Si hay error
 * en una compra, se cancela y se recrea (ver docs/PURCHASES_MODULE.md).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getMany, getOne, patchOne, postOne } from '@/api/client';
import { PurchaseSchema, type Purchase, type StorePurchaseValues, type ReceivePurchaseValues } from './schemas';
import { purchaseKeys } from './queries';

// =====================================================================
// Lookups usados por el form de crear compra
// =====================================================================

/**
 * Lista de productos activos para el autocomplete de PurchaseFormDialog.
 * Trae hasta 100 (suficiente para un dropdown interactivo). Si se
 * necesitan mas, pasar a un Combobox con paginacion server-side.
 */
const ProductLookupSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  sku: z.string().nullable().optional(),
  barcode: z.string().nullable().optional(),
  tracking_type: z.enum(['quantity', 'serialized']).optional(),
  unit_of_measure: z.string().optional(),
  base_price: z.union([z.number(), z.string()]).nullable().optional(),
  is_active: z.boolean().optional(),
});

export function useProductsForPurchase() {
  return useQuery({
    queryKey: ['purchases', 'products-lookup'] as const,
    queryFn: async () => {
      const data = await getMany<unknown>('/products?per_page=100');
      // El backend retorna paginated (data.data, data.meta), aplanamos.
      const arr = Array.isArray(data) ? data : ((data as { data?: unknown[] })?.data ?? []);
      return z.array(ProductLookupSchema).parse(arr);
    },
  });
}

export interface PurchaseListFilters {
  search?: string;
  status?: 'all' | 'draft' | 'partially_received' | 'received' | 'cancelled';
  supplier_id?: number;
  date_from?: string;
  date_to?: string;
}

function toQueryString(filters: PurchaseListFilters): string {
  const params = new URLSearchParams();
  if (filters.search) params.set('search', filters.search);
  if (filters.status && filters.status !== 'all') params.set('status', filters.status);
  if (filters.supplier_id) params.set('supplier_id', String(filters.supplier_id));
  if (filters.date_from) params.set('date_from', filters.date_from);
  if (filters.date_to) params.set('date_to', filters.date_to);
  const q = params.toString();
  return q ? `?${q}` : '';
}

export function usePurchases(filters: PurchaseListFilters = {}) {
  return useQuery({
    queryKey: purchaseKeys.list(filters as Record<string, unknown>),
    queryFn: async () => {
      const data = await getMany<unknown>(`/purchases${toQueryString(filters)}`);
      return z.array(PurchaseSchema).parse(data);
    },
  });
}

export function usePurchase(id: number) {
  return useQuery({
    queryKey: purchaseKeys.detail(id),
    queryFn: async () => {
      const data = await getOne<unknown>(`/purchases/${id}`);
      return PurchaseSchema.parse(data);
    },
    enabled: Number.isFinite(id) && id > 0,
  });
}

export function useCreatePurchase() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (values: StorePurchaseValues) =>
      postOne<StorePurchaseValues, Purchase>('/purchases', values),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: purchaseKeys.lists() });
    },
  });
}

export function useReceivePurchase() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: ReceivePurchaseValues }) =>
      patchOne<ReceivePurchaseValues, Purchase>(`/purchases/${id}/receive`, values),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: purchaseKeys.lists() });
      void qc.invalidateQueries({ queryKey: purchaseKeys.detail(id) });
    },
  });
}

export function useCancelPurchase() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => patchOne<Record<string, never>, Purchase>(`/purchases/${id}/cancel`, {}),
    onSuccess: (_, id) => {
      void qc.invalidateQueries({ queryKey: purchaseKeys.lists() });
      void qc.invalidateQueries({ queryKey: purchaseKeys.detail(id) });
    },
  });
}
