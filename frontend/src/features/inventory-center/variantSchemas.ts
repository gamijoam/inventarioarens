import { z } from 'zod';

/**
 * Schema de ProductVariant (variantes por color).
 * Refleja GET /api/products/{product}/variants y la respuesta
 * anidada de ProductResource.variants cuando viene eager-loaded.
 */

export const ProductVariantSchema = z.object({
  id: z.number().int().positive(),
  product_id: z.number().int().positive(),
  color: z.string().nullable().optional(),
  color_hex: z.string().nullable().optional(),
  sku_variant: z.string().nullable().optional(),
  barcode_variant: z.string().nullable().optional(),
  price_override: z.union([z.number(), z.string()]).nullable().optional(),
  is_active: z.boolean(),
  position: z.number().int().nonnegative(),
  /**
   * Stock disponible agregado en TODOS los almacenes. El POS
   * recalcula por almacen cuando recibe el warehouse_id.
   */
  stock_available: z.coerce.number().optional(),
  created_at: z.string().optional(),
  updated_at: z.string().optional(),
});

export type ProductVariant = z.infer<typeof ProductVariantSchema>;
