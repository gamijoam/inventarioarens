import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getOne, getPaginated, patchOne } from '@/api/client';
import { type Paginated } from '@/types/api';
import { SaleSchema, type Sale, type SaleListFilters } from './schemas';
import { saleKeys } from './queries';

function toQueryString(filters: SaleListFilters): string {
  const params = new URLSearchParams();
  if (filters.search) params.set('search', filters.search);
  if (filters.status && filters.status !== 'all') params.set('status', filters.status);
  if (filters.customer_id) params.set('customer_id', String(filters.customer_id));
  if (filters.date_from) params.set('date_from', filters.date_from);
  if (filters.date_to) params.set('date_to', filters.date_to);
  if (filters.page) params.set('page', String(filters.page));
  if (filters.per_page) params.set('per_page', String(filters.per_page));
  const q = params.toString();
  return q ? `?${q}` : '';
}

export function buildSalesQuery(filters: SaleListFilters = {}): string {
  return `/sales${toQueryString(filters)}`;
}

export function useSales(filters: SaleListFilters = {}) {
  return useQuery({
    queryKey: saleKeys.list(filters),
    queryFn: async () => {
      const response = await getPaginated<unknown>(buildSalesQuery(filters));
      return {
        ...response,
        data: z.array(SaleSchema).parse(response.data),
      } satisfies Paginated<Sale>;
    },
  });
}

export function useSale(id: number | null) {
  return useQuery({
    queryKey: id ? saleKeys.detail(id) : [...saleKeys.details(), 'empty'],
    queryFn: async () => {
      const data = await getOne<unknown>(`/sales/${id}`);
      return SaleSchema.parse(data);
    },
    enabled: Number.isFinite(id) && Number(id) > 0,
  });
}

export function useCancelSale() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => patchOne<Record<string, never>, Sale>(`/sales/${id}/cancel`, {}),
    onSuccess: (_, id) => {
      void qc.invalidateQueries({ queryKey: saleKeys.lists() });
      void qc.invalidateQueries({ queryKey: saleKeys.detail(id) });
    },
  });
}

export type { SaleListFilters };
