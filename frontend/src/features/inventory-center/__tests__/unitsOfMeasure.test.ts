import { describe, it, expect, beforeEach } from 'vitest';
import {
  BASE_UNITS_OF_MEASURE,
  getCustomUnits,
  saveCustomUnit,
  getAllUnits,
} from '../unitsOfMeasure';

describe('unitsOfMeasure (Repuestos Avilacar)', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('contiene las unidades base requeridas como unit, par, pack, lt, kg', () => {
    const values = BASE_UNITS_OF_MEASURE.map((u) => u.value);
    expect(values).toContain('unit');
    expect(values).toContain('par');
    expect(values).toContain('pack');
    expect(values).toContain('lt');
    expect(values).toContain('kg');
  });

  it('permite guardar y recuperar una unidad personalizada persistente', () => {
    const tenantId = 99;
    const created = saveCustomUnit('Kit', tenantId);
    expect(created.value).toBe('kit');
    expect(created.label).toBe('Kit (kit)');
    expect(created.isCustom).toBe(true);

    const saved = getCustomUnits(tenantId);
    expect(saved).toHaveLength(1);
    expect(saved[0]?.value).toBe('kit');

    const all = getAllUnits(tenantId);
    expect(all.some((u) => u.value === 'kit')).toBe(true);
  });
});
