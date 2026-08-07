import { beforeEach, describe, expect, it } from 'vitest';

import type { Promotion } from '@/features/promotions/schemas';

import { usePosCartStore } from '../cartStore';
import { expandPromotionItems } from '../posLogic';

const promotion: Promotion = {
  id: 15,
  name: 'Telefono + cargador',
  code: 'COMBO-50',
  benefit_type: 'fixed_bundle_price',
  price_currency: 'USD',
  price_usd: 50,
  discount_percent: null,
  discount_amount_usd: null,
  priority: 10,
  is_active: true,
  items: [],
};

describe('POS promotion selection', () => {
  beforeEach(() => {
    usePosCartStore.setState({ selectedPromotion: null });
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
});
