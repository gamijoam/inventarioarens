import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';

import { getMany } from '@/api/client';
import { productKeys } from './queries';

const PricePointSchema = z
  .object({ price: z.coerce.number().nullable().optional() })
  .passthrough()
  .nullable()
  .optional();

export const PriceHistoryEntrySchema = z.object({
  id: z.number(),
  action: z.string(),
  kind: z.enum(['base_price', 'price_list']).default('price_list'),
  price_list_id: z.number().nullable().optional(),
  price_list_name: z.string().nullable().optional(),
  price_list_code: z.string().nullable().optional(),
  before: PricePointSchema,
  after: PricePointSchema,
  created_by_name: z.string().nullable().optional(),
  created_by_email: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
});

export const PriceHistoryListSchema = z.array(PriceHistoryEntrySchema);

export type PriceHistoryEntry = z.infer<typeof PriceHistoryEntrySchema>;

export function priceChangePercent(before: number | null | undefined, after: number | null | undefined): number | null {
  if (before == null || after == null || before === 0) return null;
  return ((after - before) / before) * 100;
}

export function useProductPriceHistory(productId: number) {
  return useQuery({
    queryKey: productKeys.priceHistory(productId),
    queryFn: async () => PriceHistoryListSchema.parse(await getMany<unknown>(`/products/${productId}/price-history`)),
  });
}
