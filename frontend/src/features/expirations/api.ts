import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, postOne } from '@/api/client';
import { StockLotsResponseSchema, type StockLotFilters } from './schemas';

const keys = {
  all: ['stock-lots'] as const,
  list: (filters: StockLotFilters) => ['stock-lots', 'list', filters] as const,
};

export function buildStockLotsQuery(filters: StockLotFilters = {}): string {
  const params = new URLSearchParams();
  if (filters.status) params.set('status', filters.status);
  if (filters.days !== undefined) params.set('days', String(filters.days));
  if (filters.warehouse_id) params.set('warehouse_id', String(filters.warehouse_id));
  const query = params.toString();
  return query ? `/stock-lots?${query}` : '/stock-lots';
}

export function useStockLots(filters: StockLotFilters) {
  return useQuery({
    queryKey: keys.list(filters),
    queryFn: async () => StockLotsResponseSchema.parse((await api.get(buildStockLotsQuery(filters))).data),
  });
}

export function useWriteOffStockLot() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, reason, quantity }: { id: number; reason?: string; quantity?: number }) =>
      postOne<{ reason?: string; quantity?: number }, unknown>(`/stock-lots/${id}/write-off`, { reason, quantity }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: keys.all });
    },
  });
}
