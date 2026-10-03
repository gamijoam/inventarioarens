import { beforeEach, describe, expect, it } from 'vitest';

import { loadShowVesOnCards, saveShowVesOnCards } from './posDisplayPrefs';

describe('posDisplayPrefs', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('por defecto muestra el valor en Bs', () => {
    expect(loadShowVesOnCards()).toBe(true);
  });

  it('guarda y lee la preferencia desactivada', () => {
    saveShowVesOnCards(false);
    expect(loadShowVesOnCards()).toBe(false);
  });

  it('vuelve a activar la preferencia', () => {
    saveShowVesOnCards(false);
    saveShowVesOnCards(true);
    expect(loadShowVesOnCards()).toBe(true);
  });

  it('ante un valor corrupto cae al default (true)', () => {
    localStorage.setItem('pos_show_ves_cards', 'algo-raro');
    expect(loadShowVesOnCards()).toBe(true);
  });
});
