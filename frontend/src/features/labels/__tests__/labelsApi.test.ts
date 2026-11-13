import { describe, expect, it } from 'vitest';

import { buildLabelsQuery } from '../api';
import { ProductLabelsResponseSchema } from '../schemas';

describe('etiquetas: contrato con la API', () => {
  it('arma la consulta por productos, lista de precio, fecha o ajuste masivo', () => {
    expect(buildLabelsQuery({ productIds: [3, 5], priceListId: 2 })).toBe('/product-labels?product_ids%5B%5D=3&product_ids%5B%5D=5&price_list_id=2');
    expect(buildLabelsQuery({ changedSince: '2026-11-01' })).toBe('/product-labels?changed_since=2026-11-01');
    expect(buildLabelsQuery({ bulkAdjustmentId: 9 })).toBe('/product-labels?bulk_adjustment_id=9');
  });

  it('acepta la respuesta real con campos nulos', () => {
    const parsed = ProductLabelsResponseSchema.parse({
      data: {
        labels: [
          {
            product_id: 1,
            name: 'Malta 355ml',
            sku: 'MALTA',
            barcode: null,
            unit_of_measure: null,
            price_usd: 1.2,
            price_ves: null,
            sale_currency: 'USD',
            price_list_name: null,
            exchange_rate: null,
            exchange_rate_type_code: null,
            packagings: [{ name: 'Caja x24', factor: 24, barcode: null, price_usd: 24, price_ves: null }],
          },
        ],
        skipped: [{ product_id: 2, name: 'Sin precio', reason: 'El producto no tiene precio base configurado.' }],
        generated_at: '2026-11-13T10:00:00.000000Z',
      },
    });
    expect(parsed.data.labels[0]?.packagings[0]?.name).toBe('Caja x24');
    expect(parsed.data.skipped[0]?.reason).toContain('precio');
  });
});
