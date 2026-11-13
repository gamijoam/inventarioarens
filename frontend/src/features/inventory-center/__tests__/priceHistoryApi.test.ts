import { describe, expect, it } from 'vitest';

import { PriceHistoryListSchema, priceChangePercent } from '../priceHistoryApi';

describe('historial de precios', () => {
  it('acepta la respuesta real: precio base y lista, con creacion sin valor anterior', () => {
    const parsed = PriceHistoryListSchema.parse([
      {
        id: 2,
        action: 'updated',
        kind: 'base_price',
        price_list_id: null,
        price_list_name: 'Precio base',
        price_list_code: null,
        before: { price: 1.2 },
        after: { price: 1.44 },
        created_by_name: null,
        created_by_email: null,
        created_at: null,
      },
      {
        id: 1,
        action: 'updated',
        price_list_id: 3,
        price_list_name: 'Mayor',
        price_list_code: 'MAYOR',
        before: null,
        after: { price_list_id: 3, price: 10, currency: 'USD', exchange_rate_type_id: null, is_active: true },
        created_by_name: 'Ana',
        created_by_email: 'ana@test.test',
        created_at: '2026-11-13T10:00:00.000000Z',
      },
    ]);
    expect(parsed[0]?.kind).toBe('base_price');
    expect(parsed[1]?.kind).toBe('price_list');
  });

  it('calcula la variacion porcentual solo cuando hay precio anterior', () => {
    expect(priceChangePercent(1.2, 1.44)).toBeCloseTo(20);
    expect(priceChangePercent(null, 10)).toBeNull();
    expect(priceChangePercent(0, 10)).toBeNull();
  });
});
