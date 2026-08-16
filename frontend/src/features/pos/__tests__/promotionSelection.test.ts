import { beforeEach, describe, expect, it } from 'vitest';

import type { Promotion } from '@/features/promotions/schemas';

import { usePosCartStore } from '../cartStore';
import { expandPromotionItems, promotionLineUnitPrice } from '../posLogic';

const promotion: Promotion = {
  id: 15,
  name: 'Telefono + cargador',
  code: 'COMBO-50',
  benefit_type: 'fixed_bundle_price',
  price_currency: 'USD',
  payment_currency: 'ANY',
  price_usd: 50,
  discount_percent: null,
  discount_amount_usd: null,
  priority: 10,
  is_active: true,
  items: [],
};

describe('POS promotion selection', () => {
  beforeEach(() => {
    usePosCartStore.setState({
      selectedPromotion: null,
      selectedInvoicePromotion: null,
      comboApplications: [],
      productOfferApplications: [],
    });
  });

  it('stores the selected promotion for checkout', () => {
    usePosCartStore.getState().setSelectedPromotion(promotion);

    expect(usePosCartStore.getState().selectedPromotion).toEqual(promotion);
  });

  it('clears the selected promotion independently from the cart lines', () => {
    usePosCartStore.setState({ selectedPromotion: promotion });

    usePosCartStore.getState().clearSelectedPromotion();

    expect(usePosCartStore.getState().selectedPromotion).toBeNull();
  });

  it('keeps one invoice promotion and multiple combo instances independently', () => {
    const invoice = {
      ...promotion,
      id: 21,
      scope: 'invoice' as const,
      benefit_type: 'percent_discount' as const,
    };
    const combo = { ...promotion, scope: 'combo' as const };

    usePosCartStore.getState().setSelectedInvoicePromotion(invoice);
    usePosCartStore.getState().addComboApplication(combo, 'combo-a', 1);
    usePosCartStore.getState().addComboApplication(combo, 'combo-b', 2);

    expect(usePosCartStore.getState().selectedInvoicePromotion).toEqual(invoice);
    expect(usePosCartStore.getState().comboApplications).toEqual([
      { promotion: combo, instance_uuid: 'combo-a', sets: 1 },
      { promotion: combo, instance_uuid: 'combo-b', sets: 2 },
    ]);
  });

  it('assigns product offers to ordinary line ids without replacing combos', () => {
    const offer = {
      ...promotion,
      id: 30,
      scope: 'product_offer' as const,
      benefit_type: 'free_item' as const,
    };

    usePosCartStore.getState().addProductOfferApplication(offer, 'line-ordinary');

    expect(usePosCartStore.getState().productOfferApplications).toEqual([
      { promotion: offer, line_id: 'line-ordinary' },
    ]);
    expect(usePosCartStore.getState().comboApplications).toEqual([]);
  });

  it('expands every component for multiple promotion sets and combines duplicate products', () => {
    expect(
      expandPromotionItems(
        [
          { product_id: 10, quantity: 1 },
          { product_id: 11, quantity: 2 },
          { product_id: 10, quantity: 1 },
        ],
        5,
      ),
    ).toEqual([
      { product_id: 10, quantity: 10 },
      { product_id: 11, quantity: 10 },
    ]);
  });

  it('prorratea el precio del bundle en las unidades mostradas en el carrito', () => {
    // Bundle de $50 con 3 unidades totales -> $16.67 por unidad.
    expect(
      promotionLineUnitPrice(
        {
          benefit_type: 'fixed_bundle_price',
          price_usd: 50,
          discount_percent: null,
          discount_amount_usd: null,
        },
        100,
        3,
      ),
    ).toBe(16.67);
  });

  it('aplica el porcentaje de descuento al precio base', () => {
    expect(
      promotionLineUnitPrice(
        {
          benefit_type: 'percent_discount',
          price_usd: 0,
          discount_percent: 20,
          discount_amount_usd: null,
        },
        100,
        1,
      ),
    ).toBe(80);
  });

  it('aplica el descuento fijo al precio base sin dejarlo negativo', () => {
    expect(
      promotionLineUnitPrice(
        {
          benefit_type: 'fixed_discount',
          price_usd: 0,
          discount_percent: null,
          discount_amount_usd: 30,
        },
        100,
        1,
      ),
    ).toBe(70);
    expect(
      promotionLineUnitPrice(
        {
          benefit_type: 'fixed_discount',
          price_usd: 0,
          discount_percent: null,
          discount_amount_usd: 200,
        },
        100,
        1,
      ),
    ).toBe(0);
  });

  it('mantiene el precio base para free_item y buy_x_get_y (el descuento se resuelve en backend)', () => {
    expect(
      promotionLineUnitPrice(
        {
          benefit_type: 'free_item',
          price_usd: 0,
          discount_percent: null,
          discount_amount_usd: null,
        },
        100,
        1,
      ),
    ).toBe(100);
  });
});
