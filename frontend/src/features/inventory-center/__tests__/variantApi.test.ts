/**
 * Tests de getProductVariants: la funcion parsea la respuesta con el schema
 * Zod y descarta silenciosamente las variantes que no lo cumplen.
 *
 * Regresion 2026-08-11: las variantes sincronizadas desde la nube llegan con
 * `updated_at` en null (el applier no lo setea). Antes el schema usaba
 * `z.string().optional()` que rechaza null, por lo que la variante se
 * descartaba y "desaparecia" de la UI aunque existia en la BD. Ahora los
 * timestamps son nullable.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetOne = vi.fn();

vi.mock('@/api/client', () => ({
  getOne: (...args: unknown[]) => mockGetOne(...args),
}));

import { getProductVariants } from '../variantApi';

describe('getProductVariants', () => {
  beforeEach(() => {
    mockGetOne.mockReset();
  });

  it('mantiene variantes con updated_at null (sincronizadas) y created_at presente', async () => {
    mockGetOne.mockResolvedValue({
      data: [
        {
          id: 4889,
          product_id: 9782,
          color: 'GRIS',
          color_hex: '#888888',
          sku_variant: null,
          barcode_variant: null,
          price_override: null,
          is_active: true,
          position: 0,
          stock_available: 1,
          created_at: '2026-08-11T04:03:02.000000Z',
          updated_at: null,
        },
        {
          id: 4890,
          product_id: 9782,
          color: 'ROJO',
          color_hex: '#f50000',
          sku_variant: null,
          barcode_variant: null,
          price_override: null,
          is_active: true,
          position: 0,
          stock_available: 0,
          created_at: '2026-08-11T04:17:48.000000Z',
          updated_at: '2026-08-11T04:17:48.000000Z',
        },
      ],
    });

    const variants = await getProductVariants(9782);

    expect(mockGetOne).toHaveBeenCalledWith('/products/9782/variants');
    expect(variants).toHaveLength(2);
    expect(variants.map((variant) => variant.color)).toEqual(['GRIS', 'ROJO']);
  });

  it('mantiene variantes sin created_at ni updated_at (objeto omitido)', async () => {
    mockGetOne.mockResolvedValue({
      data: [
        {
          id: 1,
          product_id: 100,
          color: 'Azul',
          is_active: true,
          position: 0,
        },
      ],
    });

    const variants = await getProductVariants(100);

    expect(variants).toHaveLength(1);
    expect(variants[0]?.color).toBe('Azul');
  });
});
