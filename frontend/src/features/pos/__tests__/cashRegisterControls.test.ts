import { describe, expect, it } from 'vitest';

import { ValidationError } from '@/types/api';
import {
  closeConfirmationRequired,
  openSessionAgeWarning,
  sessionHasCashDifference,
  toCashMovementRequest,
} from '../cashRegisterControls';

describe('toCashMovementRequest', () => {
  it('envia el ajuste por faltante como monto negativo', () => {
    expect(toCashMovementRequest('adjustment_out', 10)).toEqual({
      type: 'adjustment',
      amount: -10,
    });
  });

  it('envia el ajuste por sobrante como monto positivo', () => {
    expect(toCashMovementRequest('adjustment_in', 10)).toEqual({ type: 'adjustment', amount: 10 });
  });

  it('mantiene entradas y salidas con monto positivo aunque se escriba con signo', () => {
    expect(toCashMovementRequest('outflow', -15)).toEqual({ type: 'outflow', amount: 15 });
    expect(toCashMovementRequest('inflow', 15)).toEqual({ type: 'inflow', amount: 15 });
  });
});

describe('closeConfirmationRequired', () => {
  it('detecta la confirmacion de conteo en cero', () => {
    const error = new ValidationError('Registraste cero efectivo', {
      confirm_zero_count: ['Registraste cero efectivo en USD y en Bs.'],
    });
    expect(closeConfirmationRequired(error)).toEqual({
      field: 'confirm_zero_count',
      message: 'Registraste cero efectivo en USD y en Bs.',
    });
  });

  it('prioriza las ordenes pendientes con abonos', () => {
    const error = new ValidationError('Hay ordenes', {
      confirm_zero_count: ['cero'],
      confirm_open_orders: ['Hay 1 orden pendiente con abonos'],
    });
    expect(closeConfirmationRequired(error)?.field).toBe('confirm_open_orders');
  });

  it('ignora otros errores de validacion', () => {
    expect(
      closeConfirmationRequired(new ValidationError('x', { closing_notes: ['nota'] })),
    ).toBeNull();
    expect(closeConfirmationRequired(new Error('red'))).toBeNull();
  });
});

describe('openSessionAgeWarning', () => {
  const now = new Date('2026-11-11T12:00:00Z');

  it('no avisa en turnos de menos de 24 horas', () => {
    expect(openSessionAgeWarning('2026-11-11T00:00:00Z', now)).toBeNull();
  });

  it('avisa en horas entre 24 y 48 horas', () => {
    expect(openSessionAgeWarning('2026-11-10T06:00:00Z', now)).toContain('30 horas');
  });

  it('avisa en dias a partir de 2 dias', () => {
    expect(openSessionAgeWarning('2026-10-01T12:00:00Z', now)).toContain('41 días');
  });

  it('tolera fechas vacias o invalidas', () => {
    expect(openSessionAgeWarning(null, now)).toBeNull();
    expect(openSessionAgeWarning('no-es-fecha', now)).toBeNull();
  });
});

describe('sessionHasCashDifference', () => {
  it('considera diferencias en USD o en Bs', () => {
    expect(sessionHasCashDifference({ difference_cash_usd: -0.01, difference_cash_ves: 0 })).toBe(
      true,
    );
    expect(sessionHasCashDifference({ difference_cash_usd: 0, difference_cash_ves: 50 })).toBe(
      true,
    );
    expect(
      sessionHasCashDifference({ difference_cash_usd: 0.004, difference_cash_ves: null }),
    ).toBe(false);
  });
});
