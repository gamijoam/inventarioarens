import { describe, expect, it } from 'vitest';

import { SalesReturnSchema, type SalesReturnPayload } from '../api';

describe('sales returns api contract', () => {
  it('parses backend sales return resources', () => {
    const parsed = SalesReturnSchema.parse({
      id: 4,
      sale_id: 15,
      status: 'processed',
      reason: 'Cliente devolvio el equipo',
      processed_at: '2026-07-18T12:00:00.000000Z',
      sale: {
        id: 15,
        receivable: {
          status: 'partial',
          balance_base_amount: '20.0000',
          balance_local_amount: '20000.0000',
          collected_base_amount: '80.0000',
          returned_base_amount: '0.0000',
        },
      },
      items: [
        {
          id: 10,
          sale_item_id: 99,
          product_id: 7,
          quantity: '1.0000',
          condition: 'sellable',
          product_unit_ids: [33],
        },
      ],
    });

    expect(parsed.items?.[0]?.quantity).toBe(1);
    expect(parsed.items?.[0]?.product_unit_ids).toEqual([33]);
    expect(parsed.sale?.receivable?.balance_base_amount).toBe(20);
  });

  it('keeps create payload aligned with POST /sales-returns', () => {
    const payload: SalesReturnPayload = {
      sale_id: 15,
      reason: 'Garantia no aplica, devolucion comercial',
      items: [
        {
          sale_item_id: 99,
          quantity: 1,
          condition: 'damaged',
          reason: 'Pantalla golpeada',
          product_unit_ids: [33],
        },
      ],
    };

    expect(payload.items[0]).toMatchObject({
      sale_item_id: 99,
      quantity: 1,
      condition: 'damaged',
      product_unit_ids: [33],
    });
  });
});
