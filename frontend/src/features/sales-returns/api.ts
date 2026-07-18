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
  refundable_base_amount: moneyValue,
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
  created_by_name: z.string().nullable().optional(),
  reviewed_by_name: z.string().nullable().optional(),
  reviewed_at: z.string().nullable().optional(),
  rejection_reason: z.string().nullable().optional(),
  processed_by_name: z.string().nullable().optional(),
  processed_at: z.string().nullable().optional(),
  cancelled_by_name: z.string().nullable().optional(),
  cancelled_at: z.string().nullable().optional(),
  cancellation_reason: z.string().nullable().optional(),
  refund_currency: z.string().nullable().optional(),
  refund_amount: moneyValue,
  refund_exchange_rate_type_id: z.number().int().nullable().optional(),
  refund_exchange_rate_type_code: z.string().nullable().optional(),
  refund_exchange_rate: moneyValue,
  refund_amount_base: moneyValue,
  refund_amount_local: moneyValue,
  refund_method: z.string().nullable().optional(),
  refund_reference: z.string().nullable().optional(),
  process_notes: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
  sale: z.object({
    id: z.number().optional(),
    customer: z.object({
      name: z.string().optional(),
      document_number: z.string().nullable().optional(),
    }).nullable().optional(),
    receivable: z.object({
      status: z.string().nullable().optional(),
      balance_base_amount: moneyValue,
      balance_local_amount: moneyValue,
      collected_base_amount: moneyValue,
      returned_base_amount: moneyValue,
    }).nullable().optional(),
  }).nullable().optional(),
  items: z.array(SalesReturnItemSchema).optional(),
}).passthrough();

export type SalesReturn = z.infer<typeof SalesReturnSchema>;
export type SalesReturnStatus = 'requested' | 'approved' | 'rejected' | 'processed' | 'cancelled';

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

export interface ProcessSalesReturnPayload {
  process_notes?: string | null;
  refund_mode?: 'none' | 'cash' | 'receivable';
  refund_currency?: 'USD' | 'VES' | null;
  refund_amount?: number | null;
  refund_method?: string | null;
  refund_reference?: string | null;
  refund_exchange_rate_type_id?: number | null;
  refund_exchange_rate?: number | null;
  refund_cash_register_session_id?: number | null;
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

export function useApproveSalesReturn() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => postOne<Record<string, never>, SalesReturn>(`/sales-returns/${id}/approve`, {}),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: salesReturnKeys.all });
      void qc.invalidateQueries({ queryKey: saleKeys.lists() });
    },
  });
}

export function useRejectSalesReturn() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => postOne<{ reason: string }, SalesReturn>(`/sales-returns/${id}/reject`, { reason }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: salesReturnKeys.all });
      void qc.invalidateQueries({ queryKey: saleKeys.lists() });
    },
  });
}

export function useProcessSalesReturn() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: ProcessSalesReturnPayload }) => postOne<ProcessSalesReturnPayload, SalesReturn>(`/sales-returns/${id}/process`, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: salesReturnKeys.all });
      void qc.invalidateQueries({ queryKey: saleKeys.lists() });
    },
  });
}

export function useCancelSalesReturn() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => postOne<{ reason: string }, SalesReturn>(`/sales-returns/${id}/cancel`, { reason }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: salesReturnKeys.all });
      void qc.invalidateQueries({ queryKey: saleKeys.lists() });
    },
  });
}
