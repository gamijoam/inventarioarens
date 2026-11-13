import { describe, expect, it } from 'vitest';

import { buildStockLotsQuery } from '../api';
import { StockLotsResponseSchema } from '../schemas';

describe('expirations api', () => {
  it('builds the stock lots query', () => {
    expect(buildStockLotsQuery({ status: 'expiring', days: 7 })).toBe('/stock-lots?status=expiring&days=7');
    expect(buildStockLotsQuery({ status: 'expired', warehouse_id: 3 })).toBe('/stock-lots?status=expired&warehouse_id=3');
  });

  it('parses the real API payload with nullable fields', () => {
    const parsed = StockLotsResponseSchema.parse({
      data: [
        {
          id: 1,
          lot_code: null,
          expires_on: '2026-11-18',
          days_left: 5,
          quantity: 4,
          unit_cost: null,
          value_base_amount: 0,
          product: { id: 2, name: 'Leche', sku: 'LECHE-1' },
          variant: null,
          warehouse: { id: 3, name: 'Almacen', code: 'ALM' },
          updated_at: null,
        },
      ],
      summary: { lots: 1, value_base_amount: 0, expired_value_base_amount: 0 },
    });

    expect(parsed.data[0]?.days_left).toBe(5);
    expect(parsed.data[0]?.lot_code).toBeNull();
    expect(parsed.summary.lots).toBe(1);
  });
});
