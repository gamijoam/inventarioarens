import { describe, expect, it } from 'vitest';

import {
  calculateCartTotals,
  calculatePaymentTotals,
  clampQuantity,
  findMatchingVariantLine,
  firstPriceIssue,
  hasPriceIssue,
  hasStockIssue,
  lineTotal,
  missingSerialIssue,
  shouldApplyInvoicePromotion,
  resolvePendingPromotionTotal,
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

  it('no vuelve a aplicar una promocion de factura sobre una orden pendiente', () => {
    const promotion = {
      benefit_type: 'percent_discount',
      price_usd: 0,
      discount_percent: 10,
      discount_amount_usd: null,
    };

    expect(shouldApplyInvoicePromotion(promotion, false)).toBe(true);
    expect(shouldApplyInvoicePromotion(promotion, true)).toBe(false);
  });

  it('restaura el total original cuando se rechaza la promocion pendiente', () => {
    expect(resolvePendingPromotionTotal(90, 100, 'validate')).toBe(90);
    expect(resolvePendingPromotionTotal(90, 100, 'reject')).toBe(100);
    expect(resolvePendingPromotionTotal(90, null, 'reject')).toBe(90);
  });

  it('calcula pagos mixtos y vuelto de efectivo', () => {
    const totals = calculatePaymentTotals(
      [
        {
          id: 'cash',
          method: 'cash',
          currency: 'USD',
          amount: 35,
          received_amount: 100,
          status: 'captured',
        },
        { id: 'card', method: 'card', currency: 'USD', amount: 16, status: 'captured' },
      ],
      51,
    );

    expect(totals).toMatchObject({
      paid: 51,
      remaining: 0,
      change: 65,
      change_currency: 'USD',
      change_amount: 65,
    });
  });

  it('convierte pagos VES a base USD usando la tasa activa de la linea', () => {
    const totals = calculatePaymentTotals(
      [
        {
          id: 'ves',
          method: 'mobile_payment',
          currency: 'VES',
          amount: 540,
          exchange_rate: 60,
          status: 'captured',
        },
      ],
      10,
    );

    expect(totals).toMatchObject({ paid: 9, remaining: 1, change: 0 });
  });

  it('calcula vuelto en USD y VES cuando un pago VES excede el total', () => {
    const totals = calculatePaymentTotals(
      [
        {
          id: 'ves',
          method: 'mobile_payment',
          currency: 'VES',
          amount: 18000,
          exchange_rate: 800,
          exchange_rate_type_id: 2,
          status: 'captured',
        },
      ],
      12.5,
    );

    expect(totals).toMatchObject({
      paid: 22.5,
      remaining: 0,
      change: 10,
      change_currency: 'VES',
      change_amount: 8000,
      change_rate: 800,
      change_rate_type_id: 2,
    });
  });

  it('bloquea cantidades superiores al stock disponible', () => {
    expect(clampQuantity(8, 3)).toBe(3);
    expect(hasStockIssue([{ ...baseLine, quantity: 6 }])).toBe(true);
  });

  it('bloquea productos serializados sin un IMEI por unidad', () => {
    expect(
      missingSerialIssue([
        {
          ...baseLine,
          tracking_type: 'serialized',
          quantity: 2,
          selected_serials: [{ id: 1, serial_number: 'IMEI-1' }],
        },
      ]),
    ).toContain('requiere 2 IMEI');
    expect(
      missingSerialIssue([
        {
          ...baseLine,
          tracking_type: 'serialized',
          quantity: 1,
          selected_serials: [{ id: 1, serial_number: 'IMEI-1' }],
        },
      ]),
    ).toBeNull();
  });

  it('detecta productos sin precio para la lista seleccionada', () => {
    const line = { ...baseLine, price_issue: 'Producto no tiene precio en Mayor.' };

    expect(hasPriceIssue([line])).toBe(true);
    expect(firstPriceIssue([line])).toBe('Producto no tiene precio en Mayor.');
    expect(hasPriceIssue([baseLine])).toBe(false);
  });

  it('separa lineas por variante/color: 1 verde y 2 azul no se mezclan', () => {
    const verde: PosCartLine = { ...baseLine, id: 'v1', product_variant_id: 1, quantity: 1 };
    const azul: PosCartLine = { ...baseLine, id: 'a1', product_variant_id: 2, quantity: 2 };

    const foundVerde = findMatchingVariantLine([verde, azul], {
      product_id: baseLine.product_id,
      warehouse_id: baseLine.warehouse_id,
      product_variant_id: 1,
    });
    const foundAzul = findMatchingVariantLine([verde, azul], {
      product_id: baseLine.product_id,
      warehouse_id: baseLine.warehouse_id,
      product_variant_id: 2,
    });

    expect(foundVerde?.id).toBe('v1');
    expect(foundVerde?.quantity).toBe(1);
    expect(foundAzul?.id).toBe('a1');
    expect(foundAzul?.quantity).toBe(2);
  });

  it('encuentra la linea sin variante (null) por separado de las de color', () => {
    const general: PosCartLine = { ...baseLine, id: 'g1', product_variant_id: null, quantity: 3 };
    const verde: PosCartLine = { ...baseLine, id: 'v1', product_variant_id: 1, quantity: 1 };

    const found = findMatchingVariantLine([general, verde], {
      product_id: baseLine.product_id,
      warehouse_id: baseLine.warehouse_id,
      product_variant_id: null,
    });

    expect(found?.id).toBe('g1');
  });
});
