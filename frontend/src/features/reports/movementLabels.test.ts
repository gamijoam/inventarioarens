import { describe, expect, it } from 'vitest';

import {
  cashMovementLabel,
  cashMovementMethodLabel,
  cashMovementTypeLabel,
} from './movementLabels';

describe('cash movement labels', () => {
  it('translates internal cash movement types and methods', () => {
    expect(cashMovementLabel('pos_payment', 'cash')).toBe('Pago POS - Efectivo');
    expect(cashMovementLabel('pos_payment', 'transfer')).toBe('Pago POS - Transferencia');
    expect(cashMovementTypeLabel('outflow')).toBe('Salida');
    expect(cashMovementMethodLabel('customer_credit')).toBe('Saldo a favor');
  });

  it('keeps unknown values readable without exposing an empty label', () => {
    expect(cashMovementTypeLabel('custom_type')).toBe('custom_type');
    expect(cashMovementMethodLabel(null)).toBe('Sin método');
  });
});
