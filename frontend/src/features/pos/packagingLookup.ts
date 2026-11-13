import { z } from 'zod';

import { getOne } from '@/api/client';
import { ProductSchema } from '@/features/inventory-center/schemas';

export const PackagingSchema = z.object({
  id: z.number(),
  product_id: z.number(),
  product_variant_id: z.number().nullable().optional(),
  name: z.string(),
  factor: z.coerce.number(),
  barcode: z.string().nullable().optional(),
  price_usd: z.coerce.number().nullable().optional(),
  unit_price_usd: z.coerce.number().nullable().optional(),
  is_active: z.boolean().optional(),
  created_at: z.string().nullable().optional(),
  updated_at: z.string().nullable().optional(),
});

export const PackagingLookupSchema = z.object({
  packaging: PackagingSchema,
  product: z.object({ id: z.number() }).passthrough(),
});

export type PosPackaging = z.infer<typeof PackagingSchema>;

/**
 * Busca una presentacion (caja, bulto) por su codigo de barras. Devuelve
 * null si el codigo no corresponde a ninguna presentacion.
 */
export async function lookupPackagingByCode(code: string) {
  try {
    const raw = PackagingLookupSchema.parse(
      await getOne<unknown>(`/product-packagings/lookup?code=${encodeURIComponent(code)}`),
    );
    const product = ProductSchema.safeParse(raw.product);
    return product.success ? { packaging: raw.packaging, product: product.data } : null;
  } catch {
    return null;
  }
}
