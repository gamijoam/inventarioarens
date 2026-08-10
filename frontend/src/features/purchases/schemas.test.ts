import { describe, expect, it } from 'vitest';

import { PurchaseItemInputSchema } from './schemas';

const baseItem = {
  warehouse_id: 1,
  product_id: 10,
  quantity: 1,
  unit_cost: 100,
  serial_units: [],
};

describe('PurchaseItemInputSchema', () => {
  it('accepts a selected product variant for purchase lines', () => {
    const result = PurchaseItemInputSchema.parse({
      ...baseItem,
      product_variant_id: 42,
    });

    expect(result.product_variant_id).toBe(42);
  });

  it('allows an unselected variant while the product has no color choice', () => {
    const result = PurchaseItemInputSchema.parse({
      ...baseItem,
      product_variant_id: null,
    });

    expect(result.product_variant_id).toBeNull();
  });

  it('rejects a non-positive variant id', () => {
    expect(() =>
      PurchaseItemInputSchema.parse({
        ...baseItem,
        product_variant_id: 0,
      }),
    ).toThrow();
  });
});
