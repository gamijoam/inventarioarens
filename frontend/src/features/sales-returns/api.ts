import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getOne, getPaginated, postOne } from '@/api/client';
import type { Paginated } from '@/types/api';
import { saleKeys } from '@/features/sales/queries';

const moneyValue = z.union([z.number(), z.string()]).nullable().optional().transform((value) => {
  if (value === null || value === undefined) return 0;
  const n = typeof value === 'string' ? Number(value) : value;
  return Number.isFinite(n) ? n : 0;
});

export const SalesReturnItemSchema = z.object({
  id: z.number(),
  sale_item_id: z.number(),
  warehouse_id: z.number().nullable().optional(),
  product_id: z.number(),
  quantity: moneyValue,
  product_unit_ids: z.array(z.number()).nullable().optional(),
  condition: z.string(),
  reason: z.string().nullable().optional(),
  product: z.object({
    id: z.number().optional(),
    name: z.string().optional(),
    sku: z.string().nullable().optional(),
  }).nullable().optional(),
  warehouse: z.object({
    id: z.number().optional(),
    name: z.string().optional(),
  }).nullable().optional(),
}).passthrough();

export const SalesReturnSchema = z.object({
  id: z.number(),
  sale_id: z.number(),
  status: z.string(),
  reason: z.string().nullable().optional(),
  processed_at: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
  sale: z.object({
    id: z.number().optional(),
    customer: z.object({
      name: z.string().optional(),
      document_number: z.string().nullable().optional(),
    }).nullable().optional(),
  }).nullable().optional(),
  items: z.array(SalesReturnItemSchema).optional(),
}).passthrough();

export type SalesReturn = z.infer<typeof SalesReturnSchema>;

export interface SalesReturnPayload {
  sale_id: number;
  reason?: string | null;
  items: Array<{
    sale_item_id: number;
    quantity: number;
    condition: 'sellable' | 'damaged';
    reason?: string | null;
    product_unit_ids?: number[];
  }>;
}

export const salesReturnKeys = {
  all: ['sales-returns'] as const,
  lists: () => [...salesReturnKeys.all, 'list'] as const,
  list: () => [...salesReturnKeys.lists()] as const,
};

export function useSalesReturns(options: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: salesReturnKeys.list(),
    enabled: options.enabled ?? true,
    queryFn: async () => {
      const response = await getPaginated<unknown>('/sales-returns');
      return {
        ...response,
        data: z.array(SalesReturnSchema).parse(response.data),
      } satisfies Paginated<SalesReturn>;
    },
  });
}

export function useSalesReturn(id: number | null) {
  return useQuery({
    queryKey: id ? [...salesReturnKeys.all, 'detail', id] : [...salesReturnKeys.all, 'detail', 'empty'],
    queryFn: async () => SalesReturnSchema.parse(await getOne<unknown>(`/sales-returns/${id}`)),
    enabled: Number.isFinite(id) && Number(id) > 0,
  });
}

export function useCreateSalesReturn() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: SalesReturnPayload) => postOne<SalesReturnPayload, SalesReturn>('/sales-returns', payload),
    onSuccess: (_data, payload) => {
      void qc.invalidateQueries({ queryKey: salesReturnKeys.all });
      void qc.invalidateQueries({ queryKey: saleKeys.lists() });
      void qc.invalidateQueries({ queryKey: saleKeys.detail(payload.sale_id) });
    },
  });
}
