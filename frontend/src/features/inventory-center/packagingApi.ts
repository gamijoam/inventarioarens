import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { deleteOne, getMany, postOne } from '@/api/client';
import { PackagingSchema } from '@/features/pos/packagingLookup';

export const ProductPackagingListSchema = z.array(PackagingSchema);
export type ProductPackaging = z.infer<typeof PackagingSchema>;

export interface PackagingFormValues {
  name: string;
  factor: string;
  barcode: string;
  price_usd: string;
}

export interface PackagingPayload {
  name: string;
  factor: number;
  barcode: string | null;
  price_usd: number | null;
}

export function buildPackagingPayload(values: PackagingFormValues): PackagingPayload {
  const price = values.price_usd.trim();
  return {
    name: values.name.trim(),
    factor: Number(values.factor),
    barcode: values.barcode.trim() || null,
    price_usd: price === '' ? null : Number(price),
  };
}

const keys = {
  list: (productId: number) => ['product-packagings', productId] as const,
};

export function useProductPackagings(productId: number | null | undefined) {
  return useQuery({
    queryKey: keys.list(Number(productId)),
    queryFn: async () => ProductPackagingListSchema.parse(await getMany<unknown>(`/products/${Number(productId)}/packagings`)),
    enabled: Number(productId) > 0,
  });
}

export function useCreatePackaging() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ productId, values }: { productId: number; values: PackagingPayload }) =>
      postOne<PackagingPayload, unknown>(`/products/${productId}/packagings`, values),
    onSuccess: (_, { productId }) => {
      void qc.invalidateQueries({ queryKey: keys.list(productId) });
    },
  });
}

export function useDeletePackaging() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id }: { id: number; productId: number }) => deleteOne(`/product-packagings/${id}`),
    onSuccess: (_, { productId }) => {
      void qc.invalidateQueries({ queryKey: keys.list(productId) });
    },
  });
}
