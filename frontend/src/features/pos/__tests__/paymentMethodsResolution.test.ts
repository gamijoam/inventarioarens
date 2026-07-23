import { describe, expect, it } from 'vitest';

import { resolvePaymentMethods } from '../PosTerminal';

describe('resolvePaymentMethods', () => {
  it('usa los metodos configurados cuando existen', () => {
    const result = resolvePaymentMethods(
      [
        { id: 2, name: 'Tarjeta', sort_order: 2, is_active: true },
        { id: 1, name: 'Efectivo', sort_order: 1, is_active: true },
      ],
      [{ id: 9, name: 'Fallback', sort_order: 99, is_active: true }],
    );

    expect(result.map((item) => item.id)).toEqual([1, 2]);
  });

  it('cae al fallback cuando bootstrap viene vacio', () => {
    const result = resolvePaymentMethods([], [
      { id: 8, name: 'Zelle', sort_order: 3, is_active: true },
      { id: 7, name: 'Transferencia', sort_order: 1, is_active: true },
    ]);

    expect(result.map((item) => item.id)).toEqual([7, 8]);
  });

  it('omite metodos inactivos', () => {
    const result = resolvePaymentMethods(
      [
        { id: 1, name: 'Activo', sort_order: 1, is_active: true },
        { id: 2, name: 'Inactivo', sort_order: 0, is_active: false },
      ],
      [],
    );

    expect(result.map((item) => item.id)).toEqual([1]);
  });
});
