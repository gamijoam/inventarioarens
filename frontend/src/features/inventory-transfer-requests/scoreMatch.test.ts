/**
 * Tests unitarios para el helper scoreMatch.
 *
 * Cubre:
 *   - SKU exacto (case-insensitive, whitespace-insensitive).
 *   - Barcode exacto.
 *   - Match por nombre parcial (contains bidireccional).
 *   - Sin match.
 *   - Edge cases: sku/barcode vacios, origen null/undefined.
 *   - compareMatches ordena por score descendente.
 */
import { describe, it, expect } from 'vitest';
import { scoreMatch, compareMatches, type ProductLiteForMatch } from './scoreMatch';

const productA: ProductLiteForMatch = {
  name: 'Coca-Cola 1.5L',
  sku: 'CC-1500',
  barcode: '7501234567890',
};

describe('scoreMatch', () => {
  it('match exacto por SKU devuelve score 100 y type sku', () => {
    const result = scoreMatch(productA, { name: 'Cualquier cosa', sku: 'CC-1500' });
    expect(result.score).toBe(100);
    expect(result.matchType).toBe('sku');
  });

  it('match por SKU es case-insensitive', () => {
    const result = scoreMatch(productA, { name: 'Cualquier cosa', sku: 'cc-1500' });
    expect(result.score).toBe(100);
    expect(result.matchType).toBe('sku');
  });

  it('match por SKU ignora whitespace al inicio/final', () => {
    const result = scoreMatch(productA, { name: 'Cualquier cosa', sku: '  CC-1500  ' });
    expect(result.score).toBe(100);
    expect(result.matchType).toBe('sku');
  });

  it('match exacto por barcode devuelve score 90 y type barcode', () => {
    const result = scoreMatch(productA, {
      name: 'Otro producto',
      sku: 'OTRO-SKU',
      barcode: '7501234567890',
    });
    expect(result.score).toBe(90);
    expect(result.matchType).toBe('barcode');
  });

  it('SKU tiene prioridad sobre barcode cuando ambos coinciden', () => {
    const result = scoreMatch(productA, {
      name: 'Otro producto',
      sku: 'CC-1500',
      barcode: '7501234567890',
    });
    expect(result.matchType).toBe('sku');
    expect(result.score).toBe(100);
  });

  it('match por nombre parcial devuelve score 60 y type name', () => {
    const result = scoreMatch(
      { name: 'Coca-Cola 1.5L', sku: 'CC-1500' },
      { name: 'Coca-Cola 1.5L Zero', sku: 'OTRO' },
    );
    expect(result.score).toBe(60);
    expect(result.matchType).toBe('name');
  });

  it('match por nombre funciona bidireccional (origen contiene destino)', () => {
    const result = scoreMatch(
      { name: 'Coca-Cola 1.5L Botella', sku: 'X' },
      { name: 'Coca-Cola 1.5L', sku: 'Y' },
    );
    expect(result.score).toBe(60);
    expect(result.matchType).toBe('name');
  });

  it('sin match devuelve score 0 y type none', () => {
    const result = scoreMatch(
      { name: 'Coca-Cola', sku: 'CC' },
      { name: 'Pepsi', sku: 'PP' },
    );
    expect(result.score).toBe(0);
    expect(result.matchType).toBe('none');
  });

  it('origen null/undefined devuelve score 0', () => {
    expect(scoreMatch(null, productA)).toEqual({ score: 0, matchType: 'none' });
    expect(scoreMatch(undefined, productA)).toEqual({ score: 0, matchType: 'none' });
  });

  it('SKU vacio en destino no matchea por SKU', () => {
    const result = scoreMatch(productA, { name: 'Otro', sku: '' });
    expect(result.matchType).not.toBe('sku');
  });

  it('SKU vacio en origen no matchea por SKU', () => {
    const result = scoreMatch({ name: 'Coca', sku: '' }, { name: 'Otro', sku: 'CC' });
    expect(result.matchType).not.toBe('sku');
  });

  it('barcode vacio no matchea', () => {
    const result = scoreMatch(productA, { name: 'X', sku: 'Y', barcode: '' });
    expect(result.matchType).not.toBe('barcode');
  });
});

describe('compareMatches', () => {
  it('mayor score primero (orden descendente)', () => {
    const a = { score: 100, matchType: 'sku' as const };
    const b = { score: 60, matchType: 'name' as const };
    expect(compareMatches(a, b)).toBeLessThan(0);
    expect(compareMatches(b, a)).toBeGreaterThan(0);
  });

  it('empate devuelve 0 (orden estable)', () => {
    const a = { score: 60, matchType: 'name' as const };
    const b = { score: 60, matchType: 'name' as const };
    expect(compareMatches(a, b)).toBe(0);
  });
});
