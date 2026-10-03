import { beforeEach, describe, expect, it } from 'vitest';

import { loadPinnedCategories, savePinnedCategories } from './pinnedCategories';

describe('pinnedCategories', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('por defecto no hay categorias fijadas', () => {
    expect(loadPinnedCategories(5)).toEqual([]);
  });

  it('guarda y lee por empresa', () => {
    savePinnedCategories(5, [1, 2, 3]);
    expect(loadPinnedCategories(5)).toEqual([1, 2, 3]);
    expect(loadPinnedCategories(6)).toEqual([]);
  });

  it('ante un valor corrupto devuelve vacio', () => {
    localStorage.setItem('inventory_pinned_categories_5', 'no-json');
    expect(loadPinnedCategories(5)).toEqual([]);
  });

  it('filtra valores no numericos', () => {
    localStorage.setItem('inventory_pinned_categories_5', JSON.stringify([1, 'x', 2, null]));
    expect(loadPinnedCategories(5)).toEqual([1, 2]);
  });
});
