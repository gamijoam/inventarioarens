import { beforeEach, describe, expect, it } from 'vitest';

import {
  clearPurchaseDraft,
  loadPurchaseDraft,
  savePurchaseDraft,
  type PurchaseDraftInput,
} from './purchaseFormDraft';

function draft(overrides: Partial<PurchaseDraftInput> = {}): PurchaseDraftInput {
  return {
    supplierId: 5,
    documentNumber: 'FACT-1',
    issuedAt: '2026-09-21',
    dueDate: '',
    currency: 'USD',
    rateTypeId: null,
    defaultWarehouseId: 2,
    items: [
      {
        warehouse_id: 2,
        product_id: 10,
        product_variant_id: null,
        product_info: null,
        quantity: 5,
        unit_cost: '2.00',
        serial_units: [],
      },
    ],
    ...overrides,
  };
}

describe('purchaseFormDraft', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('guarda y recupera el borrador por tenant', () => {
    savePurchaseDraft(1, draft());

    const loaded = loadPurchaseDraft(1);
    expect(loaded?.documentNumber).toBe('FACT-1');
    expect(loaded?.items).toHaveLength(1);
    expect(loaded?.items[0]?.product_id).toBe(10);
    expect(loaded?.savedAt).toBeTruthy();
  });

  it('no mezcla borradores de distintos tenants', () => {
    savePurchaseDraft(1, draft({ documentNumber: 'T1' }));
    savePurchaseDraft(2, draft({ documentNumber: 'T2' }));

    expect(loadPurchaseDraft(1)?.documentNumber).toBe('T1');
    expect(loadPurchaseDraft(2)?.documentNumber).toBe('T2');
    expect(loadPurchaseDraft(3)).toBeNull();
  });

  it('limpia el borrador del tenant indicado', () => {
    savePurchaseDraft(1, draft());
    clearPurchaseDraft(1);

    expect(loadPurchaseDraft(1)).toBeNull();
  });

  it('ignora contenido corrupto', () => {
    window.localStorage.setItem('inventory_purchase_draft_1', '{no-json');
    expect(loadPurchaseDraft(1)).toBeNull();
  });
});
