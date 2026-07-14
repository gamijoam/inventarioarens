import { describe, it, expect } from 'vitest';
import { StoreProductSchema, BulkActionSchema } from './schemas';

describe('StoreProductSchema', () => {
  const valid = {
    name: 'iPhone 15',
    sku: 'IPH15-128',
    barcode: '0194253714750',
    tracking_type: 'serialized',
    unit_of_measure: 'unit',
    track_stock: true,
    brand_id: 1,
    category_ids: [1, 2],
    tag_ids: [3],
    base_price: '799.00',
    sale_currency: 'USD',
    min_stock: 5,
    max_stock: 100,
    reorder_quantity: 50,
    is_active: true,
  };

  it('acepta un producto valido', () => {
    const result = StoreProductSchema.parse(valid);
    expect(result.name).toBe('iPhone 15');
    expect(result.sku).toBe('IPH15-128');
  });

  it('rechaza nombre vacio', () => {
    const result = StoreProductSchema.safeParse({ ...valid, name: '   ' });
    expect(result.success).toBe(false);
  });

  it('rechaza max_stock < min_stock', () => {
    const result = StoreProductSchema.safeParse({ ...valid, min_stock: 100, max_stock: 50 });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('max_stock'))).toBe(true);
    }
  });

  it('rechaza reorder_quantity > max - min', () => {
    const result = StoreProductSchema.safeParse({
      ...valid,
      min_stock: 10,
      max_stock: 50,
      reorder_quantity: 100,
    });
    expect(result.success).toBe(false);
  });

  it('acepta image_url vacia o http(s) válida', () => {
    expect(StoreProductSchema.safeParse({ ...valid, image_url: '' }).success).toBe(true);
    expect(StoreProductSchema.safeParse({ ...valid, image_url: 'https://example.com/x.jpg' }).success).toBe(true);
    expect(StoreProductSchema.safeParse({ ...valid, image_url: 'not-a-url' }).success).toBe(false);
  });
});

describe('BulkActionSchema', () => {
  it('activate: sin payload requerido', () => {
    const result = BulkActionSchema.safeParse({
      product_ids: [1, 2, 3],
      action: 'activate',
    });
    expect(result.success).toBe(true);
  });

  it('assign_warranty_policy: requiere payload.warranty_policy_id', () => {
    const result = BulkActionSchema.safeParse({
      product_ids: [1, 2],
      action: 'assign_warranty_policy',
      payload: {},
    });
    expect(result.success).toBe(false);
  });

  it('fill_missing_price_list: requiere payload.price_list_id + strategy', () => {
    const result = BulkActionSchema.safeParse({
      product_ids: [1],
      action: 'fill_missing_price_list',
      payload: { price_list_id: 5, strategy: 'base_price' },
    });
    expect(result.success).toBe(true);
  });

  it('requiere al menos 1 product_id', () => {
    const result = BulkActionSchema.safeParse({
      product_ids: [],
      action: 'activate',
    });
    expect(result.success).toBe(false);
  });

  it('limita a 200 product_ids', () => {
    const ids = Array.from({ length: 201 }, (_, i) => i + 1);
    const result = BulkActionSchema.safeParse({
      product_ids: ids,
      action: 'activate',
    });
    expect(result.success).toBe(false);
  });
});