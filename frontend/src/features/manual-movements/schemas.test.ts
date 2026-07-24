import { describe, expect, it } from 'vitest';
import { CreateManualMovementSchema, ManualMovementSchema } from './schemas';

describe('CreateManualMovementSchema', () => {
  const valid = {
    warehouse_id: 1,
    product_id: 2,
    quantity: 3,
    type: 'adjustment_in',
    reason: 'Conteo físico',
    notes: null,
  } as const;
  it('acepta una solicitud válida', () => {
    expect(CreateManualMovementSchema.parse(valid)).toMatchObject(valid);
  });
  it('rechaza ids inválidos, cantidad cero y motivo vacío', () => {
    const result = CreateManualMovementSchema.safeParse({
      ...valid,
      warehouse_id: 0,
      product_id: 0,
      quantity: 0,
      reason: ' ',
    });
    expect(result.success).toBe(false);
    if (!result.success)
      expect(result.error.issues.map((issue) => issue.path[0])).toEqual(
        expect.arrayContaining(['warehouse_id', 'product_id', 'quantity', 'reason']),
      );
  });
});

describe('ManualMovementSchema', () => {
  it('normaliza la cantidad recibida como texto y conserva la auditoría', () => {
    const result = ManualMovementSchema.parse({
      id: 9,
      type: 'loss',
      reason: 'Faltante',
      notes: null,
      status: 'rejected',
      product: { id: 2, name: 'Producto' },
      quantity: '2.5',
      warehouse: { id: 1, name: 'Principal' },
      creator: { id: 3, name: 'Ana' },
      rejector: { id: 4, name: 'Luis' },
      rejected_at: '2026-07-24T12:00:00Z',
      rejection_reason: 'Sin soporte',
      created_at: '2026-07-24T11:00:00Z',
    });
    expect(result.quantity).toBe(2.5);
    expect(result.rejection_reason).toBe('Sin soporte');
  });
});
