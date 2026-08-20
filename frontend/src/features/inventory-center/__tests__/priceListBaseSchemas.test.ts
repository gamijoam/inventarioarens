/**
 * Tests de schemas de listas de precios con base encadenada.
 */
import { describe, it, expect } from 'vitest';
import { StorePriceListSchema, PriceListSchema } from '../schemas';

describe('StorePriceListSchema', () => {
  it('acepta base_price_list_id y markup_percentage', () => {
    const parsed = StorePriceListSchema.safeParse({
      name: 'Precio Cashea',
      code: 'CASHEA',
      markup_percentage: 16,
      base_price_list_id: 3,
    });
    expect(parsed.success).toBe(true);
    if (!parsed.success) return;
    expect(parsed.data.base_price_list_id).toBe(3);
    expect(parsed.data.markup_percentage).toBe(16);
    expect(parsed.data.code).toBe('CASHEA');
  });

  it('permite crear sin lista base (precio base del producto)', () => {
    const parsed = StorePriceListSchema.safeParse({
      name: 'Detal',
      code: 'DETAL',
      base_price_list_id: null,
    });
    expect(parsed.success).toBe(true);
    if (!parsed.success) return;
    expect(parsed.data.base_price_list_id).toBeNull();
  });
});

describe('PriceListSchema (lectura)', () => {
  it('parsea base_price_list embebida', () => {
    const parsed = PriceListSchema.safeParse({
      id: 9,
      code: 'CASHEA',
      name: 'Precio Cashea',
      markup_percentage: 16,
      base_price_list_id: 3,
      base_price_list: { id: 3, name: 'Precio Detal', code: 'DETAL' },
      is_active: true,
    });
    expect(parsed.success).toBe(true);
    if (!parsed.success) return;
    expect(parsed.data.base_price_list?.name).toBe('Precio Detal');
    expect(parsed.data.base_price_list_id).toBe(3);
  });
});
