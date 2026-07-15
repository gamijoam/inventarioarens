import { describe, it, expect } from 'vitest';
import {
  PurchaseSchema,
  PurchaseItemSchema,
  StorePurchaseSchema,
  ReceivePurchaseSchema,
  PurchaseListFiltersSchema,
  PURCHASE_STATUSES,
  PURCHASE_STATUS_LABELS,
} from './schemas';

describe('PurchaseSchema', () => {
  it('parsea un PO con shape real del backend (montos como string)', () => {
    const result = PurchaseSchema.parse({
      id: 1,
      supplier_id: 5,
      status: 'received',
      document_number: 'PO-001',
      issued_at: '2026-07-15',
      due_date: '2026-07-22',
      purchase_currency: 'USD',
      exchange_rate_type_id: 1,
      exchange_rate_type_code: 'BCV',
      exchange_rate: '36.5000',
      total_base_amount: '1000.0000',
      total_local_amount: '36500.0000',
      received_base_amount: '1000.0000',
      received_local_amount: '36500.0000',
      items_count: 3,
      created_by: 42,
      received_at: '2026-07-15T13:00:00.000000Z',
      created_at: '2026-07-14T10:00:00.000000Z',
      updated_at: '2026-07-15T13:00:00.000000Z',
    });
    expect(result.id).toBe(1);
    expect(result.status).toBe('received');
    expect(result.purchase_currency).toBe('USD');
    // Los montos vienen como string (decimal del backend). El frontend
    // los parsea con Number() en tiempo de render para matematica.
    expect(result.total_base_amount).toBe('1000.0000');
  });

  it('rechaza status invalido', () => {
    expect(() =>
      PurchaseSchema.parse({
        id: 1,
        status: 'in_progress',
        purchase_currency: 'USD',
      }),
    ).toThrow();
  });

  it('rechaza currency invalida', () => {
    expect(() =>
      PurchaseSchema.parse({
        id: 1,
        status: 'draft',
        purchase_currency: 'EUR',
      }),
    ).toThrow();
  });

  it('acepta todos los status conocidos', () => {
    for (const s of PURCHASE_STATUSES) {
      const result = PurchaseSchema.parse({ id: 1, status: s, purchase_currency: 'USD' });
      expect(result.status).toBe(s);
      expect(PURCHASE_STATUS_LABELS[s]).toBeTruthy();
    }
  });
});

describe('PurchaseItemSchema', () => {
  it('parsea item con cantidad y received como string (decimal del backend)', () => {
    const result = PurchaseItemSchema.parse({
      id: 10,
      purchase_order_id: 1,
      warehouse_id: 3,
      product_id: 99,
      quantity: '50.0000',
      received_quantity: '25.0000',
    });
    expect(result.quantity).toBe(50);
    expect(result.received_quantity).toBe(25);
  });

  it('item serializado con N serial_units', () => {
    const result = PurchaseItemSchema.parse({
      id: 10,
      purchase_order_id: 1,
      warehouse_id: 3,
      product_id: 99,
      quantity: 3,
      received_quantity: 3,
      serial_units: [
        { serial_type: 'imei', serial_number: '123456789012345' },
        { serial_type: 'imei', serial_number: '123456789012346' },
        { serial_type: 'imei', serial_number: '123456789012347' },
      ],
    });
    expect(result.serial_units).toHaveLength(3);
  });
});

describe('StorePurchaseSchema', () => {
  it('happy path: compra USD sin supplier ni tasa', () => {
    const result = StorePurchaseSchema.parse({
      purchase_currency: 'USD',
      items: [
        {
          warehouse_id: 1,
          product_id: 100,
          quantity: 10,
          unit_cost: 5.5,
        },
      ],
    });
    expect(result.purchase_currency).toBe('USD');
    expect(result.items).toHaveLength(1);
    expect(result.items[0]?.quantity).toBe(10);
    expect(result.items[0]?.serial_units).toEqual([]);
  });

  it('compra VES con tasa', () => {
    const result = StorePurchaseSchema.parse({
      purchase_currency: 'VES',
      exchange_rate_type_id: 1,
      document_number: 'PO-2026-001',
      issued_at: '2026-07-15',
      due_date: '2026-07-30',
      items: [
        {
          warehouse_id: 1,
          product_id: 100,
          quantity: 24,
          unit_cost: 200,
          serial_units: [
            { serial_type: 'imei', serial_number: '123456789012345' },
            { serial_type: 'imei', serial_number: '123456789012346' },
          ],
        },
      ],
    });
    expect(result.purchase_currency).toBe('VES');
    expect(result.exchange_rate_type_id).toBe(1);
    expect(result.document_number).toBe('PO-2026-001');
    expect(result.items[0]?.serial_units).toHaveLength(2);
  });

  it('rechaza sin items', () => {
    expect(() =>
      StorePurchaseSchema.parse({
        purchase_currency: 'USD',
        items: [],
      }),
    ).toThrow();
  });

  it('rechaza quantity <= 0', () => {
    expect(() =>
      StorePurchaseSchema.parse({
        purchase_currency: 'USD',
        items: [{ warehouse_id: 1, product_id: 1, quantity: 0, unit_cost: 5 }],
      }),
    ).toThrow();
  });

  it('rechaza unit_cost <= 0', () => {
    expect(() =>
      StorePurchaseSchema.parse({
        purchase_currency: 'USD',
        items: [{ warehouse_id: 1, product_id: 1, quantity: 5, unit_cost: -1 }],
      }),
    ).toThrow();
  });

  it('document_number y fechas vacias se normalizan a null', () => {
    const result = StorePurchaseSchema.parse({
      purchase_currency: 'USD',
      document_number: '',
      issued_at: '',
      due_date: '',
      items: [{ warehouse_id: 1, product_id: 1, quantity: 5, unit_cost: 1 }],
    });
    expect(result.document_number).toBeNull();
    expect(result.issued_at).toBeNull();
    expect(result.due_date).toBeNull();
  });
});

describe('ReceivePurchaseSchema', () => {
  it('puede omitir items (recibe todo el pendiente)', () => {
    const result = ReceivePurchaseSchema.parse({});
    expect(result.items).toBeNull();
    expect(result.received_at).toBeNull();
  });

  it('puede especificar items parciales', () => {
    const result = ReceivePurchaseSchema.parse({
      received_at: '2026-07-15',
      items: [
        {
          purchase_item_id: 10,
          quantity: 5,
          serial_units: [{ serial_type: 'imei', serial_number: '123456789012345' }],
        },
      ],
    });
    expect(result.received_at).toBe('2026-07-15');
    expect(result.items).toHaveLength(1);
    expect(result.items?.[0]?.serial_units).toHaveLength(1);
  });

  it('rechaza received_at con formato incorrecto', () => {
    expect(() =>
      ReceivePurchaseSchema.parse({ received_at: '15/07/2026' }),
    ).toThrow();
  });
});

describe('PurchaseListFiltersSchema', () => {
  it('defaults sensatos', () => {
    const result = PurchaseListFiltersSchema.parse({});
    expect(result.search).toBe('');
    expect(result.status).toBe('all');
  });

  it('status=all explicit', () => {
    const result = PurchaseListFiltersSchema.parse({ status: 'all' });
    expect(result.status).toBe('all');
  });

  it('status especifico valido', () => {
    const result = PurchaseListFiltersSchema.parse({ status: 'received' });
    expect(result.status).toBe('received');
  });
});
