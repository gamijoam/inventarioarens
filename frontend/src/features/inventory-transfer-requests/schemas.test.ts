/**
 * Tests para schemas y api del modulo InventoryTransferRequests.
 *
 * Cubre:
 *   - StoreTransferRequestSchema: valida que se requiera destino
 *     (slug O email), valida items no vacios, normaliza reason/ref.
 *   - AcceptTransferRequestSchema: valida destination_warehouse_id y
 *     cada item con request_item_id + destination_product_id.
 *   - RejectTransferRequestSchema: response_notes opcional.
 *   - TransferRequestSchema (response): parsea la respuesta del backend
 *     con metadata opcional de paginacion.
 */
import { describe, it, expect } from 'vitest';
import {
  AcceptTransferRequestSchema,
  RejectTransferRequestSchema,
  StoreTransferRequestSchema,
  TransferRequestSchema,
} from './schemas';

describe('StoreTransferRequestSchema', () => {
  it('rechaza cuando no hay slug ni email de destino', () => {
    const r = StoreTransferRequestSchema.safeParse({
      from_warehouse_id: 1,
      items: [{ product_id: 1, quantity: 1 }],
    });
    expect(r.success).toBe(false);
  });

  it('acepta cuando viene destination_tenant_slug', () => {
    const r = StoreTransferRequestSchema.safeParse({
      destination_tenant_slug: 'otra-empresa',
      from_warehouse_id: 1,
      items: [{ product_id: 1, quantity: 5 }],
    });
    expect(r.success).toBe(true);
    if (r.success) {
      expect(r.data.reason).toBeNull();
      expect(r.data.reference).toBeNull();
    }
  });

  it('acepta cuando viene destination_user_email', () => {
    const r = StoreTransferRequestSchema.safeParse({
      destination_user_email: 'user@otra.com',
      from_warehouse_id: 1,
      items: [{ product_id: 1, quantity: 5 }],
    });
    expect(r.success).toBe(true);
  });

  it('rechaza items vacios', () => {
    const r = StoreTransferRequestSchema.safeParse({
      destination_tenant_slug: 'otra-empresa',
      from_warehouse_id: 1,
      items: [],
    });
    expect(r.success).toBe(false);
  });

  it('rechaza quantity <= 0', () => {
    const r = StoreTransferRequestSchema.safeParse({
      destination_tenant_slug: 'otra-empresa',
      from_warehouse_id: 1,
      items: [{ product_id: 1, quantity: 0 }],
    });
    expect(r.success).toBe(false);
  });

  it('normaliza reason / reference a null si vienen vacios', () => {
    const r = StoreTransferRequestSchema.safeParse({
      destination_tenant_slug: 'otra-empresa',
      from_warehouse_id: 1,
      reason: '   ',
      reference: '',
      items: [{ product_id: 1, quantity: 1 }],
    });
    expect(r.success).toBe(true);
    if (r.success) {
      expect(r.data.reason).toBeNull();
      expect(r.data.reference).toBeNull();
    }
  });
});

describe('AcceptTransferRequestSchema', () => {
  it('requiere destination_warehouse_id', () => {
    const r = AcceptTransferRequestSchema.safeParse({
      items: [{ request_item_id: 1, destination_product_id: 2 }],
    });
    expect(r.success).toBe(false);
  });

  it('requiere al menos un item', () => {
    const r = AcceptTransferRequestSchema.safeParse({
      destination_warehouse_id: 5,
      items: [],
    });
    expect(r.success).toBe(false);
  });

  it('valida cada item con request_item_id y destination_product_id positivos', () => {
    const r = AcceptTransferRequestSchema.safeParse({
      destination_warehouse_id: 5,
      items: [
        { request_item_id: 1, destination_product_id: 2 },
        { request_item_id: 2, destination_product_id: 0 },
      ],
    });
    expect(r.success).toBe(false);
  });

  it('acepta payload completo', () => {
    const r = AcceptTransferRequestSchema.safeParse({
      destination_warehouse_id: 5,
      response_notes: 'OK',
      items: [
        { request_item_id: 1, destination_product_id: 2 },
        { request_item_id: 2, destination_product_id: 3 },
      ],
    });
    expect(r.success).toBe(true);
    if (r.success) {
      expect(r.data.response_notes).toBe('OK');
      expect(r.data.items).toHaveLength(2);
    }
  });
});

describe('RejectTransferRequestSchema', () => {
  it('acepta payload vacio (response_notes opcional)', () => {
    const r = RejectTransferRequestSchema.safeParse({});
    expect(r.success).toBe(true);
    if (r.success) {
      expect(r.data.response_notes).toBeNull();
    }
  });

  it('acepta response_notes con texto', () => {
    const r = RejectTransferRequestSchema.safeParse({ response_notes: 'No tenemos stock' });
    expect(r.success).toBe(true);
    if (r.success) {
      expect(r.data.response_notes).toBe('No tenemos stock');
    }
  });
});

describe('TransferRequestSchema (response)', () => {
  it('parsea respuesta minima del backend', () => {
    const raw = {
      id: 1,
      origin_tenant_id: 1,
      destination_tenant_id: 2,
      from_warehouse_id: 10,
      status: 'requested',
      items: [],
    };
    const parsed = TransferRequestSchema.parse(raw);
    expect(parsed.id).toBe(1);
    expect(parsed.status).toBe('requested');
    expect(parsed.items).toEqual([]);
  });

  it('parsea respuesta con items y tenant embebido', () => {
    const raw = {
      id: 1,
      origin_tenant_id: 1,
      destination_tenant_id: 2,
      origin_tenant: { id: 1, name: 'Origin', slug: 'origin' },
      destination_tenant: { id: 2, name: 'Dest', slug: 'dest' },
      from_warehouse_id: 10,
      from_warehouse: { id: 10, code: 'W1' },
      status: 'completed',
      items: [
        {
          id: 100,
          origin_product_id: 1,
          destination_product_id: 2,
          quantity: 5,
        },
      ],
    };
    const parsed = TransferRequestSchema.parse(raw);
    expect(parsed.origin_tenant?.slug).toBe('origin');
    expect(parsed.items?.[0]?.destination_product_id).toBe(2);
  });

  it('coerce quantity string a number', () => {
    const raw = {
      id: 1,
      origin_tenant_id: 1,
      destination_tenant_id: 2,
      from_warehouse_id: 10,
      status: 'requested',
      items: [{ id: 1, origin_product_id: 1, quantity: '7.5' }],
    };
    const parsed = TransferRequestSchema.parse(raw);
    expect(parsed.items?.[0]?.quantity).toBe(7.5);
  });
});
