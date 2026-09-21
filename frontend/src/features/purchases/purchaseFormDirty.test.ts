import { describe, expect, it } from 'vitest';

import { isPurchaseFormDirty } from './purchaseFormDirty';
import type { PurchaseItemRowValue } from './components/PurchaseItemRow';

function emptyItem(overrides: Partial<PurchaseItemRowValue> = {}): PurchaseItemRowValue {
  return {
    warehouse_id: 1,
    product_id: null,
    product_variant_id: null,
    product_info: null,
    quantity: '',
    unit_cost: '',
    serial_units: [],
    ...overrides,
  };
}

const base = { supplierId: null, documentNumber: '', dueDate: '' };

describe('isPurchaseFormDirty', () => {
  it('considera limpio un formulario vacio', () => {
    expect(isPurchaseFormDirty({ ...base, items: [emptyItem()] })).toBe(false);
  });

  it('detecta productos cargados', () => {
    expect(
      isPurchaseFormDirty({
        ...base,
        items: [emptyItem({ product_id: 10, quantity: 5, unit_cost: '2' })],
      }),
    ).toBe(true);
  });

  it('detecta varias lineas aunque esten vacias', () => {
    expect(isPurchaseFormDirty({ ...base, items: [emptyItem(), emptyItem()] })).toBe(true);
  });

  it('detecta cabecera (proveedor, documento o vencimiento)', () => {
    expect(isPurchaseFormDirty({ ...base, supplierId: 3, items: [emptyItem()] })).toBe(true);
    expect(isPurchaseFormDirty({ ...base, documentNumber: 'F-1', items: [emptyItem()] })).toBe(true);
    expect(isPurchaseFormDirty({ ...base, dueDate: '2026-10-01', items: [emptyItem()] })).toBe(true);
  });
});
