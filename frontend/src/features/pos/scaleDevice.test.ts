import { describe, expect, it } from 'vitest';

import { convertWeightQuantity, isWeightUnit, quantityForScaleReading } from './scaleDevice';

describe('scale device weight handling', () => {
  it('converts stable readings to the product unit', () => {
    expect(
      quantityForScaleReading(
        { ok: true, status: 'ready', weight: 500, unit: 'g', is_stable: true, timestamp: new Date().toISOString() },
        'kg',
      ),
    ).toBe(0.5);
    expect(
      quantityForScaleReading(
        { ok: true, status: 'ready', weight: 1, unit: 'lb', is_stable: true, timestamp: new Date().toISOString() },
        'kg',
      ),
    ).toBe(0.453592);
  });

  it('rejects unstable, stale, disconnected and non-positive readings', () => {
    const timestamp = new Date().toISOString();
    expect(quantityForScaleReading({ ok: true, status: 'ready', weight: 1, unit: 'kg', is_stable: false, timestamp }, 'kg')).toBeNull();
    expect(quantityForScaleReading({ ok: true, status: 'waiting_for_reading', weight: 1, unit: 'kg', is_stable: true, timestamp }, 'kg')).toBeNull();
    expect(quantityForScaleReading({ ok: true, status: 'ready', weight: 0, unit: 'kg', is_stable: true, timestamp }, 'kg')).toBeNull();
    expect(quantityForScaleReading({ ok: true, status: 'ready', weight: 1, unit: 'kg', is_stable: true, timestamp: new Date(Date.now() - 10000).toISOString() }, 'kg')).toBeNull();
  });

  it('converts label quantities into the product weight unit', () => {
    expect(convertWeightQuantity(0.375, 'kg', 'g')).toBe(375);
    expect(convertWeightQuantity(2, 'libras', 'kg')).toBe(0.907185);
  });

  it('does not classify count units as weight', () => {
    expect(isWeightUnit('kg')).toBe(true);
    expect(isWeightUnit('unit')).toBe(false);
  });
});
