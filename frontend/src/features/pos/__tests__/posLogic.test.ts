import { describe, expect, it } from 'vitest';

import {
  calculateCartTotals,
  calculatePaymentTotals,
  clampQuantity,
  hasStockIssue,
  lineTotal,
  type PosCartLine,
} from '../posLogic';

const baseLine: PosCartLine = {
  id: '1',
  product_id: 10,
  name: 'Producto',
  warehouse_id: 1,
  quantity: 2,
  available_stock: 5,
  unit_price: 20,
  currency: 'USD',
};

describe('POS cart logic', () => {
  it('calcula descuentos por porcentaje y monto fijo', () => {
    expect(lineTotal({ ...baseLine, discount_type: 'percent', discount_value: 10 })).toBe(36);
    expect(lineTotal({ ...baseLine, discount_type: 'fixed', discount_value: 5 })).toBe(35);
  });

  it('calcula totales del carrito', () => {
    const totals = calculateCartTotals([
      { ...baseLine, discount_type: 'percent', discount_value: 10 },
      { ...baseLine, id: '2', quantity: 1, unit_price: 15 },
    ]);

    expect(totals).toEqual({ subtotal: 55, discount: 4, total: 51 });
  });

  it('calcula pagos mixtos y vuelto de efectivo', () => {
    const totals = calculatePaymentTotals([
      { id: 'cash', method: 'cash', currency: 'USD', amount: 35, received_amount: 100, status: 'captured' },
      { id: 'card', method: 'card', currency: 'USD', amount: 16, status: 'captured' },
    ], 51);

    expect(totals).toEqual({ paid: 51, remaining: 0, change: 65 });
  });

  it('bloquea cantidades superiores al stock disponible', () => {
    expect(clampQuantity(8, 3)).toBe(3);
    expect(hasStockIssue([{ ...baseLine, quantity: 6 }])).toBe(true);
  });
});
