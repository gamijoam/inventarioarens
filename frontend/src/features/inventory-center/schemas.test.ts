import { describe, it, expect } from 'vitest';
import {
  ProductSchema,
  PaginatedProductsSchema,
  StoreProductSchema,
  BulkActionSchema,
  StoreExchangeRateTypeSchema,
  StoreExchangeRateSchema,
  StoreBranchSchema,
  StoreWarehouseSchema,
  StoreWarrantyPolicySchema,
  StorePriceListSchema,
} from './schemas';

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

describe('ProductSchema (response del backend)', () => {
  // Caso real: backend retorna base_price/min_stock/etc como numero (float).
  // Anteriormente ProductSchema esperaba string para base_price y .int() para
  // stock levels, lo que causaba que la validacion Zod fallara y useProducts
  // quedara en error state -> inventario aparecia vacio.
  const backendSample = {
    id: 5,
    tenant_id: 1,
    name: 'Adaptador Bluetooth',
    sku: 'ADP-BT-VAL',
    barcode: null,
    description: null,
    long_description: null,
    image_url: null,
    tracking_type: 'quantity',
    unit_of_measure: 'unit',
    track_stock: true,
    brand_id: null,
    brand: null,
    categories: [],
    tags: [],
    base_price: 5000,
    sale_currency: 'USD',
    sale_exchange_rate_type_id: 1,
    sale_exchange_rate_type: {
      id: 1,
      code: 'BCV',
      name: 'Banco Central de Venezuela',
      is_default: true,
      is_active: true,
    },
    min_stock: null,
    max_stock: null,
    reorder_quantity: null,
    suggested_purchase: null,
    average_cost: null,
    average_cost_visible: true,
    warranty_policy_id: null,
    warranty_policy: null,
    can_change_tracking_type: true,
    units_count: 0,
    is_active: true,
    created_at: '2026-07-14T15:59:02+00:00',
    updated_at: '2026-07-14T15:59:02+00:00',
  };

  it('acepta el shape exacto que retorna el backend (base_price como numero)', () => {
    const result = ProductSchema.parse(backendSample);
    expect(result.id).toBe(5);
    expect(result.base_price).toBe(5000);
  });

  it('acepta stock levels con decimales (no solo enteros)', () => {
    const withFloatStock = {
      ...backendSample,
      min_stock: 5.5,
      max_stock: 100.25,
      reorder_quantity: 50.0,
    };
    expect(() => ProductSchema.parse(withFloatStock)).not.toThrow();
  });

  it('acepta base_price como string (tolerancia con endpoints legacy)', () => {
    const withStringPrice = { ...backendSample, base_price: '799.00' };
    expect(() => ProductSchema.parse(withStringPrice)).not.toThrow();
  });
});

describe('PaginatedProductsSchema', () => {
  it('acepta el shape completo del backend (data, meta, links)', () => {
    const backendPaginated = {
      data: [
        {
          id: 1,
          tenant_id: 1,
          name: 'Test',
          tracking_type: 'quantity',
          is_active: true,
        },
      ],
      meta: {
        current_page: 1,
        from: 1,
        last_page: 1,
        per_page: 25,
        to: 1,
        total: 1,
      },
      links: {
        first: 'http://localhost/api/products?page=1',
        last: 'http://localhost/api/products?page=1',
        prev: null,
        next: null,
      },
    };
    expect(() => PaginatedProductsSchema.parse(backendPaginated)).not.toThrow();
  });
});

describe('StoreExchangeRateTypeSchema', () => {
  it('acepta codigo + name validos', () => {
    const result = StoreExchangeRateTypeSchema.parse({
      code: 'bcv',
      name: 'Banco Central de Venezuela',
    });
    expect(result.code).toBe('BCV');
    expect(result.is_default).toBe(false);
    expect(result.is_active).toBe(true);
  });

  it('auto-convierte code a uppercase', () => {
    const result = StoreExchangeRateTypeSchema.parse({ code: '  paralelo  ', name: 'Paralelo' });
    expect(result.code).toBe('PARALELO');
  });

  it('rechaza code vacio', () => {
    expect(() => StoreExchangeRateTypeSchema.parse({ code: '', name: 'X' })).toThrow();
  });

  it('rechaza name vacio', () => {
    expect(() => StoreExchangeRateTypeSchema.parse({ code: 'BCV', name: '  ' })).toThrow();
  });

  it('respeta is_default y is_active explicitos', () => {
    const result = StoreExchangeRateTypeSchema.parse({
      code: 'BCV',
      name: 'BCV',
      is_default: true,
      is_active: false,
    });
    expect(result.is_default).toBe(true);
    expect(result.is_active).toBe(false);
  });
});

describe('StoreExchangeRateSchema', () => {
  it('acepta rate historico valido', () => {
    const result = StoreExchangeRateSchema.parse({
      exchange_rate_type_id: 1,
      rate: 36.5,
      effective_at: '2026-07-14',
    });
    expect(result.base_currency).toBe('USD');
    expect(result.quote_currency).toBe('VES');
    expect(result.source).toBe('manual');
    expect(result.is_active).toBe(true);
  });

  it('rechaza rate <= 0', () => {
    expect(() =>
      StoreExchangeRateSchema.parse({ exchange_rate_type_id: 1, rate: 0, effective_at: '2026-07-14' }),
    ).toThrow();
    expect(() =>
      StoreExchangeRateSchema.parse({ exchange_rate_type_id: 1, rate: -1, effective_at: '2026-07-14' }),
    ).toThrow();
  });

  it('rechaza sin exchange_rate_type_id', () => {
    expect(() => StoreExchangeRateSchema.parse({ rate: 36.5, effective_at: '2026-07-14' })).toThrow();
  });

  it('respeta currencies custom', () => {
    const result = StoreExchangeRateSchema.parse({
      exchange_rate_type_id: 1,
      base_currency: 'EUR',
      quote_currency: 'USD',
      rate: 1.1,
      effective_at: '2026-07-14',
    });
    expect(result.base_currency).toBe('EUR');
    expect(result.quote_currency).toBe('USD');
  });
});

// =====================================================================
// Catalogos administrativos: Branches, Warehouses, WarrantyPolicies, PriceLists
// =====================================================================

describe('StoreBranchSchema', () => {
  it('normaliza code a uppercase + trim', () => {
    const result = StoreBranchSchema.parse({ name: '  Centro  ', code: '  centro  ', status: 'active' });
    expect(result.name).toBe('Centro');
    expect(result.code).toBe('CENTRO');
    expect(result.status).toBe('active');
  });

  it('rechaza code vacio', () => {
    expect(() => StoreBranchSchema.parse({ name: 'X', code: '   ' })).toThrow();
  });

  it('default status=active', () => {
    const result = StoreBranchSchema.parse({ name: 'X', code: 'X' });
    expect(result.status).toBe('active');
  });
});

describe('StoreWarehouseSchema', () => {
  it('requiere branch_id positivo', () => {
    expect(() => StoreWarehouseSchema.parse({ name: 'Almacen', code: 'MAIN', branch_id: 0 })).toThrow();
    expect(() => StoreWarehouseSchema.parse({ name: 'Almacen', code: 'MAIN' })).toThrow();
  });

  it('normaliza code a uppercase + trim', () => {
    const result = StoreWarehouseSchema.parse({ branch_id: 1, name: '  Principal  ', code: '  main  ' });
    expect(result.name).toBe('Principal');
    expect(result.code).toBe('MAIN');
    expect(result.status).toBe('active');
  });
});

describe('StoreWarrantyPolicySchema', () => {
  it('happy path', () => {
    const result = StoreWarrantyPolicySchema.parse({
      name: 'Garantia 30 dias',
      duration_days: 30,
      coverage_type: 'store',
      conditions: 'No aplica a danos por agua',
      is_active: true,
    });
    expect(result.name).toBe('Garantia 30 dias');
    expect(result.duration_days).toBe(30);
    expect(result.coverage_type).toBe('store');
    expect(result.conditions).toBe('No aplica a danos por agua');
  });

  it('rechaza duration_days negativo o mayor a 3650', () => {
    expect(() =>
      StoreWarrantyPolicySchema.parse({ name: 'X', duration_days: -1, coverage_type: 'store' }),
    ).toThrow();
    expect(() =>
      StoreWarrantyPolicySchema.parse({ name: 'X', duration_days: 5000, coverage_type: 'store' }),
    ).toThrow();
  });

  it('rechaza coverage_type invalido', () => {
    expect(() =>
      StoreWarrantyPolicySchema.parse({ name: 'X', duration_days: 30, coverage_type: 'otro' }),
    ).toThrow();
  });

  it('conditions vacias se normalizan a null', () => {
    const result = StoreWarrantyPolicySchema.parse({
      name: 'X',
      duration_days: 30,
      coverage_type: 'none',
      conditions: '   ',
    });
    expect(result.conditions).toBeNull();
  });
});

describe('StorePriceListSchema', () => {
  it('happy path con code uppercase automatico', () => {
    const result = StorePriceListSchema.parse({
      name: 'Detal',
      code: 'retail',
      description: 'Precio de mostrador',
      is_default: true,
      sort_order: 1,
    });
    expect(result.code).toBe('RETAIL');
    expect(result.name).toBe('Detal');
    expect(result.is_default).toBe(true);
    expect(result.is_active).toBe(true);
    expect(result.sort_order).toBe(1);
    expect(result.payment_method_ids).toEqual([]);
  });

  it('description vacia -> null', () => {
    const result = StorePriceListSchema.parse({ name: 'X', code: 'X', description: '   ' });
    expect(result.description).toBeNull();
  });

  it('default sort_order = 0', () => {
    const result = StorePriceListSchema.parse({ name: 'X', code: 'X' });
    expect(result.sort_order).toBe(0);
  });
});
