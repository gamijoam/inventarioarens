/**
 * API del modulo de Clientes.
 * Endpoints cubiertos:
 *   - GET    /api/customers?active_only=1&search=
 *   - POST   /api/customers
 *   - GET    /api/customers/{id}
 *   - PATCH  /api/customers/{id}
 *   - DELETE /api/customers/{id}
 *   - GET    /api/customer-groups
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { deleteOne, getMany, getOne, patchOne, postOne } from '@/api/client';
import { CustomerSchema, type Customer } from './schemas';
import { customerKeys } from './queries';

export interface CustomerFilters {
  search?: string;
  active_only?: boolean;
}

function toQueryString(filters: CustomerFilters): string {
  const params = new URLSearchParams();
  if (filters.search) params.set('search', filters.search);
  if (filters.active_only) params.set('active_only', '1');
  const q = params.toString();
  return q ? `?${q}` : '';
}

export function useCustomers(filters: CustomerFilters = {}) {
  return useQuery({
    queryKey: customerKeys.list(filters as Record<string, unknown>),
    queryFn: async () => {
      const data = await getMany<unknown>(`/customers${toQueryString(filters)}`);
      return z.array(CustomerSchema).parse(data);
    },
  });
}

export function useCustomer(id: number) {
  return useQuery({
    queryKey: customerKeys.detail(id),
    queryFn: async () => {
      const data = await getOne<unknown>(`/customers/${id}`);
      return CustomerSchema.parse(data);
    },
    enabled: Number.isFinite(id) && id > 0,
  });
}

export function useCreateCustomer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, Customer>('/customers', input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: customerKeys.lists() });
    },
  });
}

export function useUpdateCustomer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...input }: { id: number; [k: string]: unknown }) =>
      patchOne<Record<string, unknown>, Customer>(`/customers/${id}`, input),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: customerKeys.lists() });
      void qc.invalidateQueries({ queryKey: customerKeys.detail(id) });
    },
  });
}

export function useDeleteCustomer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/customers/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: customerKeys.lists() });
    },
  });
}

// Customer groups (lookup simple).
const CustomerGroupSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  description: z.string().nullable().optional(),
});

export function useCustomerGroups() {
  return useQuery({
    queryKey: [...customerKeys.all, 'groups'] as const,
    queryFn: async () => {
      const data = await getMany<unknown>('/customer-groups');
      return z.array(CustomerGroupSchema).parse(data);
    },
  });
}
