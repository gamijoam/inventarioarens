import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getPaginated, patchOne, postOne } from '@/api/client';
import type { Paginated } from '@/types/api';

const moneyValue = z.union([z.number(), z.string()]).nullable().optional().transform((value) => {
  if (value === null || value === undefined) return null;
  const n = typeof value === 'string' ? Number(value) : value;
  return Number.isFinite(n) ? n : null;
});

export const WarrantyClaimSchema = z.object({
  id: z.number(),
  sale_id: z.number().nullable().optional(),
  sale_item_id: z.number(),
  customer_name: z.string().nullable().optional(),
  customer_phone: z.string().nullable().optional(),
  product_name: z.string().nullable().optional(),
  product_unit_serial: z.string().nullable().optional(),
  replacement_product_unit_id: z.number().nullable().optional(),
  status: z.string(),
  quantity: z.union([z.number(), z.string()]).transform(Number),
  issue_description: z.string(),
  received_notes: z.string().nullable().optional(),
  diagnosis: z.string().nullable().optional(),
  resolution_type: z.string().nullable().optional(),
  resolution_notes: z.string().nullable().optional(),
  refund_currency: z.string().nullable().optional(),
  refund_amount: moneyValue,
  received_at: z.string().nullable().optional(),
  reviewed_at: z.string().nullable().optional(),
  delivered_at: z.string().nullable().optional(),
  resolved_at: z.string().nullable().optional(),
  warranty_policy_name: z.string().nullable().optional(),
  warranty_expires_at: z.string().nullable().optional(),
}).passthrough();

export type WarrantyClaim = z.infer<typeof WarrantyClaimSchema>;

export interface WarrantyClaimPayload {
  sale_item_id: number;
  product_unit_id?: number | null;
  quantity?: number;
  customer_name?: string | null;
  customer_phone?: string | null;
  issue_description: string;
  received_notes?: string | null;
}

export const warrantyKeys = {
  all: ['warranties'] as const,
  claims: () => [...warrantyKeys.all, 'claims'] as const,
};

export function useWarrantyClaims(options: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: warrantyKeys.claims(),
    enabled: options.enabled ?? true,
    queryFn: async () => {
      const response = await getPaginated<unknown>('/warranty-claims');
      return {
        ...response,
        data: z.array(WarrantyClaimSchema).parse(response.data),
      } satisfies Paginated<WarrantyClaim>;
    },
  });
}

export function useCreateWarrantyClaim() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: WarrantyClaimPayload) => postOne<WarrantyClaimPayload, WarrantyClaim>('/warranty-claims', payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: warrantyKeys.claims() });
    },
  });
}

export function useReviewWarrantyClaim() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: { status: string; diagnosis?: string; resolution_type?: string; resolution_notes?: string } }) =>
      patchOne<typeof payload, WarrantyClaim>(`/warranty-claims/${id}/review`, payload),
    onSuccess: () => void qc.invalidateQueries({ queryKey: warrantyKeys.claims() }),
  });
}

export function useResolveWarrantyClaim() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: { resolution_type: string; resolution_notes?: string } }) =>
      patchOne<typeof payload, WarrantyClaim>(`/warranty-claims/${id}/resolve`, payload),
    onSuccess: () => void qc.invalidateQueries({ queryKey: warrantyKeys.claims() }),
  });
}

export function useDeliverWarrantyClaim() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, resolution_notes }: { id: number; resolution_notes?: string }) =>
      patchOne<{ resolution_notes?: string }, WarrantyClaim>(`/warranty-claims/${id}/deliver`, { resolution_notes }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: warrantyKeys.claims() }),
  });
}
