import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getOne, getPaginated, postOne } from '@/api/client';
import type { Paginated } from '@/types/api';
import { payableKeys } from './queries';
import {
  PayableSchema,
  PayPayableSchema,
  type Payable,
  type PayableListFilters,
  type PayablePayment,
  type PayPayableValues,
} from './schemas';

export function buildPayablesQuery(filters: PayableListFilters = {}): string {
  const params = new URLSearchParams();
  if (filters.search) params.set('search', filters.search);
  if (filters.status && filters.status !== 'all') params.set('status', filters.status);
  if (filters.supplier_id) params.set('supplier_id', String(filters.supplier_id));
  if (filters.due_from) params.set('due_from', filters.due_from);
  if (filters.due_to) params.set('due_to', filters.due_to);
  if (filters.page) params.set('page', String(filters.page));
  if (filters.limit) params.set('limit', String(filters.limit));
  const query = params.toString();
  return `/accounts-payable${query ? `?${query}` : ''}`;
}

export function usePayables(filters: PayableListFilters = {}) {
  return useQuery({
    queryKey: payableKeys.list(filters as Record<string, unknown>),
    queryFn: async () => {
      const response = await getPaginated<unknown>(buildPayablesQuery(filters));
      const data = z.array(PayableSchema).parse(response.data);
      return {
        ...response,
        data,
      } satisfies Paginated<Payable>;
    },
  });
}

export function usePayable(id: number | null) {
  return useQuery({
    queryKey: id ? payableKeys.detail(id) : [...payableKeys.details(), 'empty'],
    queryFn: async () => PayableSchema.parse(await getOne<unknown>(`/accounts-payable/${id}`)),
    enabled: Number.isFinite(id) && Number(id) > 0,
  });
}

export function usePayPayable() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: PayPayableValues }) =>
      postOne<PayPayableValues, PayablePayment>(
        `/accounts-payable/${id}/payments`,
        PayPayableSchema.parse(values),
      ),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: payableKeys.lists() });
      void qc.invalidateQueries({ queryKey: payableKeys.detail(id) });
      void qc.invalidateQueries({ queryKey: ['pos', 'cash-sessions'] });
    },
  });
}

export type { PayableListFilters };
