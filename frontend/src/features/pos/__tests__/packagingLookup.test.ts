import { describe, expect, it } from 'vitest';

import { PackagingLookupSchema } from '../packagingLookup';

describe('lookup de presentaciones', () => {
  it('parsea la respuesta real del backend', () => {
    const parsed = PackagingLookupSchema.parse({
      packaging: {
        id: 9,
        product_id: 1,
        product_variant_id: null,
        name: 'Caja x24',
        factor: 24,
        barcode: '7590000000240',
        price_usd: 24,
        unit_price_usd: 1,
        is_active: true,
        created_at: null,
        updated_at: null,
      },
      product: { id: 1, name: 'Malta', sku: 'MALTA', base_price: '1.2000' },
    });

    expect(parsed.packaging.factor).toBe(24);
    expect(parsed.packaging.price_usd).toBe(24);
    expect(parsed.product.id).toBe(1);
  });
});
