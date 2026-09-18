import { describe, it, expect, beforeEach } from 'vitest';
import {
  BASE_UNITS_OF_MEASURE,
  getCustomUnits,
  saveCustomUnit,
  getAllUnits,
  formatUnitLabel,
} from '../unitsOfMeasure';

describe('unitsOfMeasure', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('incluye "par" en BASE_UNITS_OF_MEASURE', () => {
    const parOption = BASE_UNITS_OF_MEASURE.find((u) => u.value === 'par');
    expect(parOption).toBeDefined();
    expect(parOption?.label).toContain('Par');
  });

  it('permite crear y persistir unidades personalizadas en localStorage por tenant', () => {
    const tenantId = 999;
    const unit = saveCustomUnit('Docena de pares', tenantId);

    expect(unit.value).toBe('docena de pares');
    expect(unit.isCustom).toBe(true);

    const saved = getCustomUnits(tenantId);
    expect(saved).toHaveLength(1);
    expect(saved[0]?.value).toBe('docena de pares');

    // Otro tenant no debe ver las unidades del primero
    expect(getCustomUnits(888)).toHaveLength(0);
  });

  it('no duplica unidades si ya existen en las unidades base', () => {
    const unit = saveCustomUnit('PAR', 123);
    expect(unit.value).toBe('par');
    expect(unit.isCustom).toBeUndefined();
    expect(getCustomUnits(123)).toHaveLength(0);
  });

  it('getAllUnits combina unidades base y personalizadas', () => {
    saveCustomUnit('display', 5);
    const all = getAllUnits(5);
    expect(all.some((u) => u.value === 'par')).toBe(true);
    expect(all.some((u) => u.value === 'display')).toBe(true);
  });

  it('getAllUnits preserva el valor actual de un producto aunque sea exótico', () => {
    const all = getAllUnits(5, 'tambor');
    expect(all.some((u) => u.value === 'tambor')).toBe(true);
  });

  it('formatUnitLabel formatea correctamente unidades conocidas y desconocidas', () => {
    expect(formatUnitLabel('par')).toBe('Par (par)');
    expect(formatUnitLabel('unit')).toBe('Unidad (und)');
    expect(formatUnitLabel('kg')).toBe('Kilogramo (kg)');
    expect(formatUnitLabel('bidon')).toBe('BIDON');
    expect(formatUnitLabel(null)).toBe('Unidad (und)');
  });
});
