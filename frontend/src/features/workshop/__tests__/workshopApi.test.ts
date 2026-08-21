/**
 * Tests del parser real de ServiceOrder (contracto backend -> JSON -> Zod).
 * Se parsea la respuesta real de la API (incluye campos nullable/timestamps).
 */
import { describe, expect, it } from 'vitest';

import { ServiceOrderSchema, ServiceOrderPartSchema } from '../api';

const realServiceOrderResponse = {
  id: 1,
  tenant_id: 1,
  order_number: 'SO-000001',
  type: 'warranty',
  warranty_claim_id: 5,
  customer_id: null,
  customer_name: 'Juan Perez',
  customer_phone: '04121234567',
  device_description: 'iPhone 11 64GB',
  issue_description: 'Pantalla rota',
  diagnosis: 'Cambio de pantalla',
  status: 'delivered',
  priority: 'normal',
  resolution: 'workshop',
  technician_id: 3,
  technician: { id: 3, name: 'Carlos Tecnico' },
  warehouse_id: 1,
  warehouse: { id: 1, code: 'WH-1', name: 'Taller' },
  labor_base_amount: '35.0000',
  labor_local_amount: '0.0000',
  parts_base_amount: '50.0000',
  parts_local_amount: '0.0000',
  total_base_amount: '85.0000',
  total_local_amount: '0.0000',
  notes: null,
  parts: [
    {
      id: 1,
      service_order_id: 1,
      product_id: 10,
      product: { id: 10, name: 'Pantalla iPhone 11', sku: 'PANT-IP11' },
      product_variant_id: null,
      warehouse_id: 1,
      quantity: '2.0000',
      unit_cost: '20.0000',
      unit_price: '25.0000',
      base_unit_price: '25.0000',
      base_unit_cost: '20.0000',
      stock_movement_id: null,
      status: 'pending',
      created_at: '2026-08-21T14:00:00+00:00',
      updated_at: '2026-08-21T14:00:00+00:00',
    },
  ],
  created_by: 1,
  received_at: '2026-08-21T14:00:00+00:00',
  technician_assigned_at: null,
  diagnosed_at: '2026-08-21T14:10:00+00:00',
  completed_at: null,
  delivered_at: '2026-08-21T15:00:00+00:00',
  cancelled_at: null,
  created_at: '2026-08-21T14:00:00+00:00',
  updated_at: '2026-08-21T15:00:00+00:00',
};

describe('ServiceOrderSchema (respuesta real de la API)', () => {
  it('parsea la respuesta completa del backend', () => {
    const parsed = ServiceOrderSchema.parse(realServiceOrderResponse);

    expect(parsed.order_number).toBe('SO-000001');
    expect(parsed.type).toBe('warranty');
    expect(parsed.status).toBe('delivered');
    expect(parsed.resolution).toBe('workshop');
    expect(parsed.technician?.name).toBe('Carlos Tecnico');
    expect(parsed.labor_base_amount).toBe(35);
    expect(parsed.total_base_amount).toBe(85);
    expect(parsed.parts).toHaveLength(1);
    expect(parsed.parts?.[0]?.quantity).toBe(2);
    expect(parsed.created_at).toBeTruthy();
    expect(parsed.delivered_at).toBeTruthy();
  });

  it('tolera campos nullable (null) sin descartar el recurso', () => {
    const response = {
      ...realServiceOrderResponse,
      customer_id: null,
      technician_id: null,
      technician: null,
      diagnosis: null,
      resolution: null,
      notes: null,
      received_at: null,
      diagnosed_at: null,
      delivered_at: null,
      created_at: null,
      updated_at: null,
      parts: [],
    };

    const parsed = ServiceOrderSchema.parse(response);
    expect(parsed.id).toBe(1);
    expect(parsed.technician).toBeNull();
    expect(parsed.parts).toHaveLength(0);
  });
});

describe('ServiceOrderPartSchema', () => {
  it('parsea una pieza con cantidad y precios decimales', () => {
    const parsed = ServiceOrderPartSchema.parse(realServiceOrderResponse.parts[0]);
    expect(parsed.quantity).toBe(2);
    expect(parsed.unit_price).toBe(25);
    expect(parsed.status).toBe('pending');
    expect(parsed.product?.name).toBe('Pantalla iPhone 11');
  });
});
