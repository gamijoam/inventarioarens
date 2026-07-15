import { describe, it, expect } from 'vitest';
import { z } from 'zod';
import { TransferSchema } from './schemas';

const backendJson = {
  id: 1,
  sequence: 1,
  document_number: 'TRF-000001',
  guide_number: 'GUIA-000001',
  type: 'internal',
  validation_mode: 'simple',
  from_warehouse_id: 3,
  to_warehouse_id: 4,
  from_warehouse: {
    id: 3, tenant_id: 2, branch_id: 2, name: 'ALMACEN2', code: 'ALMACEN2',
    status: 'active', created_at: '2026-07-15T01:22:54.000000Z', updated_at: '2026-07-15T01:22:54.000000Z',
  },
  to_warehouse: {
    id: 4, tenant_id: 2, branch_id: 2, name: 'ALMACEN PRINCIPAL', code: 'ALMACEN-PRINCIPAL',
    status: 'active', created_at: '2026-07-15T13:55:44.000000Z', updated_at: '2026-07-15T13:55:53.000000Z',
  },
  guide: {
    id: 1, tenant_id: 2, inventory_transfer_id: 1, guide_number: 'GUIA-000001',
    status: 'completed', issued_at: '2026-07-15T15:31:56.000000Z',
    prepared_at: '2026-07-15T15:31:56.000000Z',
    dispatched_at: '2026-07-15T15:31:56.000000Z',
    received_at: '2026-07-15T15:31:56.000000Z',
    issued_by: 2, prepared_by: 2, dispatched_by: 2, received_by: 2,
    metadata: { mode: 'simple', source: 'inventory_transfer_service' },
    notes: null, created_at: '2026-07-15T15:31:56.000000Z', updated_at: '2026-07-15T15:31:56.000000Z',
  },
  status: 'completed',
  reason: 'PORQUE SI',
  reference: '6362',
  notes: null,
  created_by: 2,
  processed_at: '2026-07-15T15:31:56.000000Z',
  items: [
    {
      id: 1,
      inventory_transfer_id: 1,
      product_id: 1,
      product: { id: 1, name: 'TEST', sku: 'T1', tracking_type: 'quantity' },
      quantity: '5.0000',
      requested_quantity: '5.0000',
      prepared_quantity: '5.0000',
      received_quantity: '5.0000',
      difference_quantity: '0.0000',
    },
  ],
  total_base_amount: '0.0000',
  total_local_amount: '0.0000',
  received_base_amount: '0.0000',
  received_local_amount: '0.0000',
  items_count: 1,
  created_at: '2026-07-15T15:31:56.000000Z',
  updated_at: '2026-07-15T15:31:56.000000Z',
};

describe('TransferSchema (parseo contra backend real)', () => {
  it('parsea el JSON real del backend sin error', () => {
    const result = TransferSchema.safeParse(backendJson);
    if (!result.success) {
      // eslint-disable-next-line no-console
      console.log('ISSUES:', JSON.stringify(result.error.issues, null, 2));
    }
    expect(result.success).toBe(true);
  });

  it('parsea el JSON dentro de un array (como el listado)', () => {
    const result = z.array(TransferSchema).safeParse([backendJson]);
    if (!result.success) {
      // eslint-disable-next-line no-console
      console.log('ARRAY ISSUES:', JSON.stringify(result.error.issues, null, 2));
    }
    expect(result.success).toBe(true);
  });
});