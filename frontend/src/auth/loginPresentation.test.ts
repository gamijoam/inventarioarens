import { describe, expect, it } from 'vitest';

import { getLoginPresentation } from './loginPresentation';

describe('login presentation', () => {
  it('uses administrative copy and action', () => {
    const presentation = getLoginPresentation('admin');

    expect(presentation.eyebrow).toBe('Control administrativo');
    expect(presentation.title).toBe('Entra a tu espacio de trabajo');
    expect(presentation.submitLabel).toBe('Entrar al sistema');
    expect(presentation.theme).toBe('admin');
  });

  it('uses POS copy and terminal-focused action', () => {
    const presentation = getLoginPresentation('pos');

    expect(presentation.eyebrow).toBe('Terminal de caja');
    expect(presentation.title).toBe('Listo para vender');
    expect(presentation.submitLabel).toBe('Entrar al POS');
    expect(presentation.theme).toBe('pos');
  });
});
