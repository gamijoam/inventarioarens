import { describe, expect, it } from 'vitest';

import { applyKey, canSearch, keyAction, normalizeSearch } from '../armOrderLogic';

describe('keyAction (teclado on-screen)', () => {
  it('interpreta letras y numeros como caracteres', () => {
    expect(keyAction('a')).toEqual({ type: 'char', char: 'A' });
    expect(keyAction('5')).toEqual({ type: 'char', char: '5' });
  });

  it('interpreta ESPACIO y BORRAR', () => {
    expect(keyAction('ESPACIO')).toEqual({ type: 'space' });
    expect(keyAction('BORRAR')).toEqual({ type: 'backspace' });
  });
});

describe('applyKey', () => {
  it('agrega un caracter', () => {
    expect(applyKey('ADA', { type: 'char', char: 'P' })).toBe('ADAP');
  });

  it('agrega un espacio (sin duplicar)', () => {
    expect(applyKey('ADAP', { type: 'space' })).toBe('ADAP ');
    expect(applyKey('ADAP ', { type: 'space' })).toBe('ADAP ');
  });

  it('borra el ultimo caracter', () => {
    expect(applyKey('ADAP', { type: 'backspace' })).toBe('ADA');
    expect(applyKey('', { type: 'backspace' })).toBe('');
  });
});

describe('normalizeSearch / canSearch', () => {
  it('normaliza a minusculas y colapsa espacios', () => {
    expect(normalizeSearch('  ADAP   TADOR  ')).toBe('adaptador');
  });

  it('requiere al menos 2 caracteres utiles', () => {
    expect(canSearch('a')).toBe(false);
    expect(canSearch('ad')).toBe(true);
    expect(canSearch('  ad  ')).toBe(true);
  });
});
