import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PosCartLine } from '../posLogic';
import { CartLineRow } from '../PosTerminal';

describe('CartLineRow price list selector', () => {
  it('changes the selected product list without invoking a ticket-wide change', () => {
    const onPriceListChange = vi.fn();
    const line: PosCartLine = {
      id: 'line-a',
      product_id: 12,
      name: 'Producto A',
      warehouse_id: 1,
      quantity: 1,
      available_stock: 4,
      unit_price: 100,
      base_unit_price: 100,
      currency: 'USD',
      base_currency: 'USD',
      price_list_id: 3,
      price_source: 'price_list',
      price_list_name: 'P3 - Detal',
    };

    render(
      <CartLineRow
        line={line}
        priceLists={[{ id: 3, code: 'P3', name: 'Detal', is_active: true }]}
        defaultPriceListId={3}
        priceListDisabled={false}
        onPriceListChange={onPriceListChange}
        canDiscount={false}
        onChange={vi.fn()}
        onSerials={vi.fn()}
        onRemove={vi.fn()}
      />,
    );

    const selector = screen.getByTestId('pos-line-price-list-line-a');
    expect(selector).toHaveValue('3');
    fireEvent.change(selector, { target: { value: 'base' } });

    expect(onPriceListChange).toHaveBeenCalledTimes(1);
    expect(onPriceListChange).toHaveBeenCalledWith(null);
  });
});
