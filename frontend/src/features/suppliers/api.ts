/**
 * API del modulo de Proveedores.
 * Endpoints cubiertos:
 *   - GET    /api/suppliers?active_only=1&search=
 *   - POST   /api/suppliers
 *   - GET    /api/suppliers/{id}
 *   - PATCH  /api/suppliers/{id}
 *   - DELETE /api/suppliers/{id}
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { deleteOne, getMany, getOne, patchOne, postOne } from '@/api/client';
import { SupplierSchema, type Supplier } from './schemas';
import { supplierKeys } from './queries';

export interface SupplierFilters {
  search?: string;
  active_only?: boolean;
}

function toQueryString(filters: SupplierFilters): string {
  const params = new URLSearchParams();
  if (filters.search) params.set('search', filters.search);
  if (filters.active_only) params.set('active_only', '1');
  const q = params.toString();
  return q ? `?${q}` : '';
}

export function useSuppliers(filters: SupplierFilters = {}) {
  return useQuery({
    queryKey: supplierKeys.list(filters as Record<string, unknown>),
    queryFn: async () => {
      const data = await getMany<unknown>(`/suppliers${toQueryString(filters)}`);
      return z.array(SupplierSchema).parse(data);
    },
  });
}

export function useSupplier(id: number) {
  return useQuery({
    queryKey: supplierKeys.detail(id),
    queryFn: async () => {
      const data = await getOne<unknown>(`/suppliers/${id}`);
      return SupplierSchema.parse(data);
    },
    enabled: Number.isFinite(id) && id > 0,
  });
}

export function useCreateSupplier() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, Supplier>('/suppliers', input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: supplierKeys.lists() });
    },
  });
}

export function useUpdateSupplier() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...input }: { id: number; [k: string]: unknown }) =>
      patchOne<Record<string, unknown>, Supplier>(`/suppliers/${id}`, input),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: supplierKeys.lists() });
      void qc.invalidateQueries({ queryKey: supplierKeys.detail(id) });
    },
  });
}

export function useDeleteSupplier() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/suppliers/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: supplierKeys.lists() });
    },
  });
}
