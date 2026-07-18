import { describe, expect, it } from 'vitest';

import { WarrantyClaimSchema, type WarrantyClaimPayload } from '../api';

describe('warranty claims api contract', () => {
  it('parses backend warranty claim resources', () => {
    const parsed = WarrantyClaimSchema.parse({
      id: 8,
      sale_id: 15,
      sale_item_id: 99,
      product_unit_serial: 'IMEI-001',
      product_name: 'Telefono',
      status: 'received',
      quantity: '1.0000',
      issue_description: 'No enciende',
      received_at: '2026-07-18T12:00:00.000000Z',
    });

    expect(parsed.quantity).toBe(1);
    expect(parsed.product_unit_serial).toBe('IMEI-001');
  });

  it('keeps create payload aligned with POST /warranty-claims', () => {
    const payload: WarrantyClaimPayload = {
      sale_item_id: 99,
      product_unit_id: 33,
      quantity: 1,
      customer_name: 'Gabriel',
      customer_phone: '27144475',
      issue_description: 'No enciende',
      received_notes: 'Recibido con caja',
    };

    expect(payload).toMatchObject({
      sale_item_id: 99,
      product_unit_id: 33,
      issue_description: 'No enciende',
    });
  });
});
