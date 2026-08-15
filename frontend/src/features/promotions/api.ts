import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { deleteOne, getMany, patchOne, postOne } from '@/api/client';

import {
  PromotionSchema,
  type Promotion,
  StorePromotionSchema,
  type StorePromotionInput,
} from './schemas';

export const promotionKeys = {
  all: ['promotions'] as const,
  lists: () => [...promotionKeys.all, 'list'] as const,
  available: (warehouseId: number | null, productIds: number[], selectable: boolean) =>
    [...promotionKeys.all, 'available', warehouseId, productIds, selectable] as const,
};

const promotionListSchema = z.array(PromotionSchema);

function parsePromotions(data: unknown): Promotion[] {
  return promotionListSchema.parse(data);
}

export function usePromotions(activeOnly = false) {
  return useQuery({
    queryKey: [...promotionKeys.lists(), { activeOnly }],
    queryFn: async () => {
      const query = activeOnly ? '?active_only=1' : '';
      return parsePromotions(await getMany<unknown>(`/promotions${query}`));
    },
  });
}

export function useAvailablePosPromotions({
  warehouseId,
  productIds,
  enabled = true,
  selectable = false,
}: {
  warehouseId: number | null;
  productIds: number[];
  enabled?: boolean;
  selectable?: boolean;
}) {
  return useQuery({
    queryKey: promotionKeys.available(warehouseId, productIds, selectable),
    enabled: enabled && warehouseId !== null,
    queryFn: async () => {
      const params = new URLSearchParams();
      params.set('warehouse_id', String(warehouseId));
      if (selectable) params.set('selectable', '1');
      productIds.forEach((id) => params.append('product_ids[]', String(id)));
      return parsePromotions(await getMany<unknown>(`/pos/promotions/available?${params.toString()}`));
    },
  });
}

export function useCreatePromotion() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: StorePromotionInput) =>
      postOne<StorePromotionInput, Promotion>('/promotions', StorePromotionSchema.parse(input)),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: promotionKeys.all });
    },
  });
}

export function useUpdatePromotion() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, ...input }: StorePromotionInput & { id: number }) =>
      patchOne<StorePromotionInput, Promotion>(`/promotions/${id}`, StorePromotionSchema.parse(input)),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: promotionKeys.all });
    },
  });
}

export function useDeletePromotion() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/promotions/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: promotionKeys.all });
    },
  });
}
