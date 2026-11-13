import { describe, expect, it } from 'vitest';

import { ProductPackagingListSchema, buildPackagingPayload } from '../packagingApi';

describe('packaging api', () => {
  it('parsea la lista real con campos nulos', () => {
    const parsed = ProductPackagingListSchema.parse([
      { id: 1, product_id: 2, product_variant_id: null, name: 'Caja x24', factor: 24, barcode: null, price_usd: null, unit_price_usd: null, is_active: true, created_at: null, updated_at: null },
    ]);
    expect(parsed[0]?.factor).toBe(24);
    expect(parsed[0]?.price_usd).toBeNull();
  });

  it('arma el payload con numeros y sin campos vacios', () => {
    expect(buildPackagingPayload({ name: ' Caja x24 ', factor: '24', barcode: '', price_usd: '' })).toEqual({ name: 'Caja x24', factor: 24, barcode: null, price_usd: null });
    expect(buildPackagingPayload({ name: 'Bulto', factor: '12', barcode: '759', price_usd: '10.5' })).toEqual({ name: 'Bulto', factor: 12, barcode: '759', price_usd: 10.5 });
  });
});
