import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getOne, getPaginated, postOne } from '@/api/client';
import type { Paginated } from '@/types/api';
import {
  CollectReceivableSchema,
  ReceivableSchema,
  type CollectReceivableValues,
  type Receivable,
  type ReceivableListFilters,
  type ReceivablePayment,
} from './schemas';
import { receivableKeys } from './queries';

function idempotencyConfig(): { headers: { 'Idempotency-Key': string } } {
  const key = globalThis.crypto?.randomUUID?.()
    ?? `ar-payment-${Date.now()}-${Math.random().toString(36).slice(2)}`;

  return { headers: { 'Idempotency-Key': key } };
}

export function buildReceivablesQuery(filters: ReceivableListFilters = {}): string {
  const params = new URLSearchParams();
  if (filters.search) params.set('search', filters.search);
  if (filters.status && filters.status !== 'all') params.set('status', filters.status);  if (filters.customer_id) params.set('customer_id', String(filters.customer_id));
  if (filters.due_from) params.set('due_from', filters.due_from);
  if (filters.due_to) params.set('due_to', filters.due_to);
  if (filters.page) params.set('page', String(filters.page));
  if (filters.limit) params.set('limit', String(filters.limit));
  const query = params.toString();
  return `/accounts-receivable${query ? `?${query}` : ''}`;
}

export function useReceivables(filters: ReceivableListFilters = {}) {
  return useQuery({
    queryKey: receivableKeys.list(filters),
    queryFn: async () => {
      const response = await getPaginated<unknown>(buildReceivablesQuery(filters));
      return {
        ...response,
        data: z.array(ReceivableSchema).parse(response.data),
      } satisfies Paginated<Receivable>;
    },
  });
}

export function useReceivable(id: number | null) {
  return useQuery({
    queryKey: id ? receivableKeys.detail(id) : [...receivableKeys.details(), 'empty'],
    queryFn: async () => ReceivableSchema.parse(await getOne<unknown>(`/accounts-receivable/${id}`)),
    enabled: Number.isFinite(id) && Number(id) > 0,
  });
}

export function useCollectReceivable() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: CollectReceivableValues }) =>
      postOne<CollectReceivableValues, ReceivablePayment>(
        `/accounts-receivable/${id}/payments`,
        CollectReceivableSchema.parse(values),
        idempotencyConfig(),
      ),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: receivableKeys.lists() });
      void qc.invalidateQueries({ queryKey: receivableKeys.detail(id) });
      void qc.invalidateQueries({ queryKey: ['pos', 'cash-sessions'] });
    },
  });
}

export async function exportReceivables(filters: ReceivableListFilters, format: 'csv' | 'pdf'): Promise<void> {
  const { api } = await import('@/api/client');
  const params = new URLSearchParams();
  if (filters.search) params.set('search', filters.search);
  if (filters.status && filters.status !== 'all') params.set('status', filters.status);
  if (filters.customer_id) params.set('customer_id', String(filters.customer_id));
  if (filters.due_from) params.set('due_from', filters.due_from);
  if (filters.due_to) params.set('due_to', filters.due_to);
  params.set('format', format);
  const response = await api.get(`/accounts-receivable/export?${params.toString()}`, {
    responseType: 'blob',
  });
  const url = URL.createObjectURL(response.data as Blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `cxc-${new Date().toISOString().slice(0, 10)}.${format}`;
  anchor.click();
  URL.revokeObjectURL(url);
}

export type { ReceivableListFilters };
