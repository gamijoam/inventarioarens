import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getMany, getOne, postOne, patchOne, deleteOne } from '@/api/client';
import { z } from 'zod';

export const QuotationItemSchema = z.object({
  id: z.number().int(),
  quotation_id: z.number().int(),
  product_id: z.number().int(),
  product_variant_id: z.number().int().nullable().optional(),
  product_name: z.string(),
  sku: z.string().nullable().optional(),
  quantity: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  price_list_id: z.number().int().nullable().optional(),
  unit_price_base: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  unit_price_local: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  discount_base: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  discount_local: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  total_base: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  total_local: z.union([z.number(), z.string()]).transform((v) => Number(v)),
});
export type QuotationItem = z.infer<typeof QuotationItemSchema>;

export const QuotationSchema = z.object({
  id: z.number().int(),
  sequence: z.number().int(),
  document_number: z.string(),
  customer_id: z.number().int().nullable().optional(),
  customer_name: z.string().nullable().optional(),
  warehouse_id: z.number().int().nullable().optional(),
  warehouse: z.unknown().nullable().optional(),
  status: z.enum(['draft', 'issued', 'cancelled', 'converted']),
  valid_until: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  subtotal_base_amount: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  subtotal_local_amount: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  discount_base_amount: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  discount_local_amount: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  total_base_amount: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  total_local_amount: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  exchange_rate_type_code: z.string().nullable().optional(),
  exchange_rate: z.number().nullable().optional(),
  issued_at: z.string().nullable().optional(),
  converted_at: z.string().nullable().optional(),
  converted_pos_order_id: z.number().int().nullable().optional(),
  created_by: z.number().int().nullable().optional(),
  created_at: z.string().nullable().optional(),
  updated_at: z.string().nullable().optional(),
  items: z.array(QuotationItemSchema).optional(),
});
export type Quotation = z.infer<typeof QuotationSchema>;

export const StoreQuotationItemSchema = z.object({
  product_id: z.coerce.number().int().positive(),
  product_variant_id: z.coerce.number().int().positive().optional(),
  quantity: z.coerce.number().positive(),
  price_list_id: z.coerce.number().int().positive().optional(),
});

export const quotationKeys = {
  all: ['quotations'] as const,
  list: (filters?: Record<string, unknown>) => [...quotationKeys.all, 'list', filters ?? {}] as const,
  detail: (id: number) => [...quotationKeys.all, 'detail', id] as const,
};

export function useQuotations(filters?: Record<string, unknown>) {
  return useQuery({
    queryKey: quotationKeys.list(filters),
    queryFn: async () => {
      const params = new URLSearchParams();
      Object.entries(filters ?? {}).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') params.set(key, String(value));
      });
      const query = params.toString();
      const data = await getMany<unknown>(`/quotations${query ? `?${query}` : ''}`);
      return z.array(QuotationSchema).parse(Array.isArray(data) ? data : []);
    },
  });
}

export function useQuotation(id: number) {
  return useQuery({
    queryKey: quotationKeys.detail(id),
    queryFn: async () => {
      const data = await getOne<{ data: unknown }>(`/quotations/${id}`);
      const parsed = QuotationSchema.safeParse(data?.data ?? data);
      if (!parsed.success) throw new Error('Respuesta de cotización inválida');
      return parsed.data;
    },
    enabled: id > 0,
  });
}

export function useCreateQuotation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: unknown) => {
      const data = await postOne<unknown, { data: unknown }>('/quotations', payload);
      return QuotationSchema.parse(data?.data ?? data);
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: quotationKeys.all }),
  });
}

export function useUpdateQuotation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: unknown }) => {
      const data = await patchOne<unknown, { data: unknown }>(`/quotations/${id}`, payload);
      return QuotationSchema.parse(data?.data ?? data);
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: quotationKeys.all }),
  });
}

export function useCancelQuotation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await deleteOne(`/quotations/${id}`);
      return id;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: quotationKeys.all }),
  });
}

export function useConvertQuotation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      const data = await postOne<undefined, { data: unknown }>(`/quotations/${id}/convert`);
      return data?.data ?? data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: quotationKeys.all }),
  });
}

export function quotationPdfUrl(id: number): string {
  return `/api/quotations/${id}/pdf`;
}
