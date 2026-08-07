import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetMany = vi.fn();

vi.mock('@/api/client', () => ({
  getMany: (path: string) => mockGetMany(path),
  postOne: vi.fn(),
  patchOne: vi.fn(),
  deleteOne: vi.fn(),
}));

import { useAvailablePosPromotions } from './api';
import { PromotionSchema } from './schemas';

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}

describe('promotions frontend contract', () => {
  beforeEach(() => {
    mockGetMany.mockReset();
  });

  it('parses a fixed USD bundle with arbitrary configured price', () => {
    const promotion = PromotionSchema.parse({
      id: 15,
      name: 'Telefono + cargador',
      code: 'COMBO-50',
      benefit_type: 'fixed_bundle_price',
      price_currency: 'USD',
      price_usd: '50.0000',
      discount_percent: null,
      discount_amount_usd: null,
      priority: 10,
      is_active: true,
      items: [
        { id: 1, product_id: 10, product_name: 'Telefono', quantity: '1.0000', sort_order: 0 },
        { id: 2, product_id: 11, product_name: 'Cargador', quantity: '1.0000', sort_order: 1 },
      ],
    });

    expect(promotion.price_usd).toBe(50);
    expect(promotion.items).toHaveLength(2);
    expect(promotion.items[0]?.quantity).toBe(1);
  });

  it('parses a percentage discount without a fixed USD price', () => {
    const promotion = PromotionSchema.parse({
      id: 16,
      name: 'Descuento telefono',
      code: 'PHONE-25',
      benefit_type: 'percent_discount',
      price_currency: 'USD',
      price_usd: null,
      discount_percent: '25.00',
      discount_amount_usd: null,
      priority: 20,
      is_active: true,
      items: [{ product_id: 10, product_name: 'Telefono', quantity: 1 }],
    });

    expect(promotion.discount_percent).toBe(25);
    expect(promotion.price_usd).toBe(0);
    expect(promotion.items).toHaveLength(1);
  });

  it('parses a fixed discount without a bundle price', () => {
    const promotion = PromotionSchema.parse({
      id: 17,
      name: 'Descuento fijo',
      code: 'PHONE-10',
      benefit_type: 'fixed_discount',
      price_currency: 'USD',
      price_usd: null,
      discount_percent: null,
      discount_amount_usd: '10.0000',
      priority: 15,
      is_active: true,
      items: [{ product_id: 10, product_name: 'Telefono', quantity: 1 }],
    });

    expect(promotion.discount_amount_usd).toBe(10);
    expect(promotion.price_usd).toBe(0);
  });

  it('parses a fixed item price per unit', () => {
    const promotion = PromotionSchema.parse({
      id: 18,
      name: 'Precio fijo por telefono',
      code: 'PHONE-30',
      benefit_type: 'fixed_item_price',
      price_currency: 'USD',
      price_usd: '30.0000',
      discount_percent: null,
      discount_amount_usd: null,
      priority: 12,
      is_active: true,
      items: [{ product_id: 10, product_name: 'Telefono', quantity: 1 }],
    });

    expect(promotion.price_usd).toBe(30);
    expect(promotion.items).toHaveLength(1);
  });

  it('parses a free item promotion without monetary configuration', () => {
    const promotion = PromotionSchema.parse({
      id: 19,
      name: 'Telefono gratis',
      code: 'FREE-PHONE',
      benefit_type: 'free_item',
      price_currency: 'USD',
      price_usd: null,
      discount_percent: null,
      discount_amount_usd: null,
      priority: 8,
      is_active: true,
      items: [{ product_id: 10, product_name: 'Telefono', quantity: 1 }],
    });

    expect(promotion.price_usd).toBe(0);
    expect(promotion.items).toHaveLength(1);
  });

  it('parses buy X get Y roles', () => {
    const promotion = PromotionSchema.parse({
      id: 20,
      name: 'Compra telefono recibe cargador',
      code: 'BUY-GET',
      benefit_type: 'buy_x_get_y',
      price_currency: 'USD',
      price_usd: null,
      discount_percent: null,
      discount_amount_usd: null,
      priority: 5,
      is_active: true,
      items: [
        { product_id: 10, product_name: 'Telefono', quantity: 1, item_role: 'trigger' },
        { product_id: 11, product_name: 'Cargador', quantity: 1, item_role: 'reward' },
      ],
    });

    expect(promotion.items.map((item) => item.item_role)).toEqual(['trigger', 'reward']);
  });

  it('requests POS promotions using warehouse and current cart products', async () => {
    mockGetMany.mockResolvedValue([
      {
        id: 15,
        name: 'Telefono + cargador',
        code: 'COMBO-50',
        benefit_type: 'fixed_bundle_price',
        price_currency: 'USD',
        price_usd: '50.0000',
        priority: 10,
        is_active: true,
        items: [],
      },
    ]);

    const { result } = renderHook(
      () => useAvailablePosPromotions({ warehouseId: 4, productIds: [10, 11] }),
      { wrapper },
    );

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockGetMany).toHaveBeenCalledWith(
      '/pos/promotions/available?warehouse_id=4&product_ids%5B%5D=10&product_ids%5B%5D=11',
    );
    expect(result.current.data?.[0]?.code).toBe('COMBO-50');
  });
});
