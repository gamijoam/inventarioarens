import { describe, expect, it } from 'vitest';

import { sortPriceListsByCode } from '../priceLists';

describe('sortPriceListsByCode', () => {
  it('ordena P1, P2, P3 por code (no por id)', () => {
    const lists = [
      { id: 4, code: 'P3', name: 'PRECIO 3' },
      { id: 5, code: 'P1', name: 'PRECIO 1' },
      { id: 6, code: 'P2', name: 'PRECIO 2' },
    ];

    expect(sortPriceListsByCode(lists).map((list) => list.code)).toEqual(['P1', 'P2', 'P3']);
  });

  it('no muta el arreglo original', () => {
    const lists = [
      { id: 2, code: 'P2', name: 'PRECIO 2' },
      { id: 1, code: 'P1', name: 'PRECIO 1' },
    ];

    sortPriceListsByCode(lists);

    expect(lists.map((list) => list.code)).toEqual(['P2', 'P1']);
  });

  it('usa el name como respaldo cuando no hay code', () => {
    const lists = [
      { id: 1, code: null, name: 'Zeta' },
      { id: 2, code: null, name: 'Alfa' },
    ];

    expect(sortPriceListsByCode(lists).map((list) => list.name)).toEqual(['Alfa', 'Zeta']);
  });

  it('respeta el sort_order programado prioritariamente', () => {
    const lists = [
      { id: 1, code: 'P3', name: 'Lista Tercera', sort_order: 3 },
      { id: 2, code: 'P1', name: 'Lista Primera', sort_order: 1 },
      { id: 3, code: 'P2', name: 'Lista Segunda', sort_order: 2 },
    ];

    expect(sortPriceListsByCode(lists).map((list) => list.code)).toEqual(['P1', 'P2', 'P3']);
  });

  it('usa code natural cuando los sort_order son iguales', () => {
    const lists = [
      { id: 1, code: 'P2', name: 'Mayor', sort_order: 0 },
      { id: 2, code: 'P1', name: 'Detal', sort_order: 0 },
    ];

    expect(sortPriceListsByCode(lists).map((list) => list.code)).toEqual(['P1', 'P2']);
  });
});
