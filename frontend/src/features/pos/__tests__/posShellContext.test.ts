import { describe, expect, it } from 'vitest';

import { buildPosShellContext } from '../PosTerminal';

const baseInput = {
  tenantName: 'Danubio',
  branchName: 'Soledad',
  warehouseName: 'Almacén Principal',
  cashRegisterName: 'Caja 1',
  rateLabel: 'BCV @ 36.5',
};

describe('buildPosShellContext', () => {
  it('expone el contexto operativo de una sesión abierta', () => {
    expect(
      buildPosShellContext({
        ...baseInput,
        bootstrapLoading: false,
        hasActiveSession: true,
        isOnline: true,
      }),
    ).toEqual({
      ...baseInput,
      sessionStatus: 'open',
      syncStatus: 'online',
    });
  });

  it('mantiene el estado de carga mientras el bootstrap no termina', () => {
    expect(
      buildPosShellContext({
        ...baseInput,
        bootstrapLoading: true,
        hasActiveSession: false,
        isOnline: false,
      }),
    ).toMatchObject({
      sessionStatus: 'loading',
      syncStatus: 'offline',
    });
  });

  it('marca turno cerrado cuando no hay sesión activa y el bootstrap terminó', () => {
    expect(
      buildPosShellContext({
        ...baseInput,
        bootstrapLoading: false,
        hasActiveSession: false,
        isOnline: true,
      }),
    ).toMatchObject({
      sessionStatus: 'closed',
      syncStatus: 'online',
    });
  });
});
