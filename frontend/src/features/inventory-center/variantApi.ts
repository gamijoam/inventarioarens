import { useQuery } from '@tanstack/react-query';

import { getOne } from '@/api/client';
import { ProductVariantSchema, type ProductVariant } from './variantSchemas';

export const productVariantKeys = {
  all: ['product-variants'] as const,
  byProduct: (productId: number, warehouseId?: number | null) =>
    [...productVariantKeys.all, productId, warehouseId ?? 'all'] as const,
};

export async function getProductVariants(
  productId: number,
  warehouseId?: number | null,
): Promise<ProductVariant[]> {
  const query = warehouseId ? `?warehouse_id=${warehouseId}` : '';
  const data = await getOne<unknown>(`/products/${productId}/variants${query}`);
  const raw = Array.isArray(data) ? data : ((data as { data?: unknown[] }).data ?? []);
  return raw
    .map((entry) => ProductVariantSchema.safeParse(entry))
    .filter((parsed) => parsed.success)
    .map((parsed) => (parsed as { success: true; data: ProductVariant }).data);
}

/**
 * Lista las variantes de un producto. Cuando se pasa warehouse_id,
 * el endpoint devuelve el stock disponible de cada variante en ese
 * almacen (usado por el POS para mostrar la cantidad real por color).
 *
 * Solo retorna variantes activas (filter is_active=true en backend).
 */
export function useProductVariants(productId: number, warehouseId?: number | null) {
  return useQuery({
    queryKey: productVariantKeys.byProduct(productId, warehouseId),
    queryFn: () => getProductVariants(productId, warehouseId),
    enabled: Number.isFinite(productId) && productId > 0,
    staleTime: 30_000,
  });
}
