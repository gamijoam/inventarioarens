import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetMany = vi.fn<(path: string) => Promise<unknown[]>>();
const mockPostOne = vi.fn<(path: string, body: unknown) => Promise<unknown>>();
const mockPatchOne = vi.fn<(path: string, body: unknown) => Promise<unknown>>();
const mockDeleteOne = vi.fn<(path: string) => Promise<void>>();

vi.mock('@/api/client', () => ({
  getMany: (path: string) => mockGetMany(path),
  postOne: (path: string, body: unknown) => mockPostOne(path, body),
  patchOne: (path: string, body: unknown) => mockPatchOne(path, body),
  deleteOne: (path: string) => mockDeleteOne(path),
}));

import {
  useAvailablePosPromotions,
  useCombos,
  useCreateCombo,
  useCreateInvoicePromotion,
  useCreateProductOffer,
  useDeleteCombo,
  useDeleteInvoicePromotion,
  useDeleteProductOffer,
  useInvoicePromotions,
  usePosCombos,
  usePosInvoicePromotions,
  usePosProductOffers,
  useProductOffers,
  useUpdateCombo,
  useUpdateInvoicePromotion,
  useUpdateProductOffer,
} from './api';
import { PromotionSchema, type StorePromotionInput } from './schemas';

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}

describe('promotions frontend contract', () => {
  beforeEach(() => {
    mockGetMany.mockReset();
    mockPostOne.mockReset();
    mockPatchOne.mockReset();
    mockDeleteOne.mockReset();
  });

  it('parses backend scope, combination policy and nullable timestamps', () => {
    const promotion = PromotionSchema.parse({
      id: 12,
      name: 'Factura combinable',
      code: null,
      scope: 'invoice',
      allows_combos: true,
      benefit_type: 'fixed_discount',
      price_currency: 'USD',
      payment_currency: 'ANY',
      price_usd: null,
      discount_percent: null,
      discount_amount_usd: '5.00',
      priority: 1,
      is_active: true,
      items: [],
      created_at: null,
      updated_at: '2026-08-16T12:00:00.000000Z',
    });

    expect(promotion.scope).toBe('invoice');
    expect(promotion.allows_combos).toBe(true);
    expect(promotion.created_at).toBeNull();
    expect(promotion.updated_at).toBe('2026-08-16T12:00:00.000000Z');
  });

  it.each([
    ['percent_discount', [], 'invoice'],
    ['fixed_discount', [{ product_id: 10, quantity: 1 }], 'legacy_product_discount'],
    ['fixed_bundle_price', [{ product_id: 10, quantity: 1 }], 'combo'],
    ['buy_x_get_y', [{ product_id: 10, quantity: 1 }], 'combo'],
    ['fixed_item_price', [{ product_id: 10, quantity: 1 }], 'product_offer'],
    ['free_item', [{ product_id: 10, quantity: 1 }], 'product_offer'],
  ])('infers missing scope for %s compatibility', (benefitType, items, expectedScope) => {
    const promotion = PromotionSchema.parse({
      id: 13,
      name: 'Legacy mock',
      benefit_type: benefitType,
      price_currency: 'USD',
      price_usd: null,
      priority: 0,
      is_active: true,
      items,
    });

    expect(promotion.scope).toBe(expectedScope);
    expect(promotion.allows_combos).toBe(false);
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

  it('parses an invoice percentage discount without products', () => {
    const promotion = PromotionSchema.parse({
      id: 21,
      name: 'Descuento de factura',
      code: 'INVOICE-25',
      benefit_type: 'percent_discount',
      price_currency: 'USD',
      price_usd: null,
      discount_percent: '25.00',
      discount_amount_usd: null,
      priority: 20,
      is_active: true,
      items: [],
    });

    expect(promotion.items).toEqual([]);
    expect(promotion.discount_percent).toBe(25);
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

  it('requests every matching promotion when the POS enables selection', async () => {
    mockGetMany.mockResolvedValue([]);

    const { result } = renderHook(
      () => useAvailablePosPromotions({ warehouseId: 4, productIds: [10], selectable: true }),
      { wrapper },
    );

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockGetMany).toHaveBeenCalledWith(
      '/pos/promotions/available?warehouse_id=4&selectable=1&product_ids%5B%5D=10',
    );
  });

  it('uses separate admin and POS discovery paths for every promotion domain', async () => {
    mockGetMany.mockResolvedValue([]);

    const { result } = renderHook(
      () => ({
        invoice: useInvoicePromotions(),
        combos: useCombos(),
        offers: useProductOffers(),
        posInvoice: usePosInvoicePromotions({ warehouseId: 4, productIds: [] }),
        posCombos: usePosCombos({ warehouseId: 4, productIds: [10] }),
        posOffers: usePosProductOffers({ warehouseId: 4, productIds: [10], selectable: true }),
      }),
      { wrapper },
    );

    await waitFor(() =>
      expect(Object.values(result.current).every((query) => query.isSuccess)).toBe(true),
    );

    expect(mockGetMany).toHaveBeenCalledWith('/invoice-promotions');
    expect(mockGetMany).toHaveBeenCalledWith('/combos');
    expect(mockGetMany).toHaveBeenCalledWith('/product-offers');
    expect(mockGetMany).toHaveBeenCalledWith('/pos/invoice-promotions?warehouse_id=4');
    expect(mockGetMany).toHaveBeenCalledWith('/pos/combos?warehouse_id=4');
    expect(mockGetMany).toHaveBeenCalledWith(
      '/pos/product-offers?warehouse_id=4&selectable=1&product_ids%5B%5D=10',
    );
  });

  it.each([
    [
      'invoice',
      useCreateInvoicePromotion,
      useUpdateInvoicePromotion,
      useDeleteInvoicePromotion,
      '/invoice-promotions',
    ],
    ['combo', useCreateCombo, useUpdateCombo, useDeleteCombo, '/combos'],
    [
      'product offer',
      useCreateProductOffer,
      useUpdateProductOffer,
      useDeleteProductOffer,
      '/product-offers',
    ],
  ] as const)(
    'uses the %s endpoint family for create, update and delete',
    async (_domain, useCreate, useUpdate, useDelete, endpoint) => {
      mockPostOne.mockResolvedValue({});
      mockPatchOne.mockResolvedValue({});
      mockDeleteOne.mockResolvedValue(undefined);
      const { result } = renderHook(
        () => ({ create: useCreate(), update: useUpdate(), remove: useDelete() }),
        { wrapper },
      );
      const input: StorePromotionInput = {
        name: 'Promoción de prueba',
        code: '',
        benefit_type:
          endpoint === '/invoice-promotions'
            ? 'percent_discount'
            : endpoint === '/combos'
              ? 'fixed_bundle_price'
              : 'fixed_item_price',
        price_currency: 'USD' as const,
        payment_currency: 'ANY' as const,
        allows_combos: endpoint === '/invoice-promotions',
        price_usd: endpoint === '/invoice-promotions' ? null : 20,
        discount_percent: endpoint === '/invoice-promotions' ? 10 : null,
        discount_amount_usd: null,
        priority: 0,
        is_active: true,
        items:
          endpoint === '/invoice-promotions'
            ? []
            : endpoint === '/combos'
              ? [
                  { product_id: 10, quantity: 1 },
                  { product_id: 11, quantity: 1 },
                ]
              : [{ product_id: 10, quantity: 1 }],
      };

      await act(async () => {
        await result.current.create.mutateAsync(input);
        await result.current.update.mutateAsync({ id: 12, ...input });
        await result.current.remove.mutateAsync(12);
      });

      expect(mockPostOne).toHaveBeenCalledWith(endpoint, expect.any(Object));
      expect(mockPatchOne).toHaveBeenCalledWith(`${endpoint}/12`, expect.any(Object));
      expect(mockDeleteOne).toHaveBeenCalledWith(`${endpoint}/12`);
    },
  );
});
