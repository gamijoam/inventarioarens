import { describe, expect, it, vi } from 'vitest';

import { PERMISSIONS } from '@/permissions/constants';

import { buildPosShellActions } from '../PosTerminal';

describe('buildPosShellActions', () => {
  const callbacks = () => ({
    onOpenCash: vi.fn(),
    onOpenPending: vi.fn(),
    onOpenReceipt: vi.fn(),
  });

  it('expone las acciones operativas con sus permisos y callbacks', () => {
    const cb = callbacks();

    const actions = buildPosShellActions(cb);

    expect(actions.map(({ id, label, permission }) => ({ id, label, permission }))).toEqual([
      { id: 'cash', label: 'Caja', permission: PERMISSIONS.CASH_REGISTER_VIEW },
      { id: 'pending', label: 'Pendientes', permission: PERMISSIONS.POS_VIEW },
      { id: 'receipt', label: 'Recibo', permission: PERMISSIONS.POS_VIEW },
    ]);

    actions[0]?.onClick();
    actions[1]?.onClick();
    actions[2]?.onClick();

    expect(cb.onOpenCash).toHaveBeenCalledOnce();
    expect(cb.onOpenPending).toHaveBeenCalledOnce();
    expect(cb.onOpenReceipt).toHaveBeenCalledOnce();
  });

  it('en modo vendedor solo expone Pendientes y Recibo (sin Caja)', () => {
    const actions = buildPosShellActions(callbacks(), true);

    expect(actions.map(({ id, label }) => ({ id, label }))).toEqual([
      { id: 'pending', label: 'Pendientes' },
      { id: 'receipt', label: 'Recibo' },
    ]);
    expect(actions.some((action) => action.id === 'cash')).toBe(false);
  });

  it('agrega el badge con el conteo de pendientes a la accion Pendientes', () => {
    const actions = buildPosShellActions(callbacks(), false, 5);
    const pending = actions.find((action) => action.id === 'pending');

    expect(pending?.badge).toBe(5);

    const sinBadge = buildPosShellActions(callbacks(), false, 0).find(
      (action) => action.id === 'pending',
    );
    expect(sinBadge?.badge).toBe(0);
  });

  it('resalta la accion Pendientes cuando hay una alerta de orden nueva', () => {
    const actions = buildPosShellActions(callbacks(), false, 2, true);
    const pending = actions.find((action) => action.id === 'pending');

    expect(pending?.alert).toBe(true);

    const sinAlerta = buildPosShellActions(callbacks(), false, 2, false).find(
      (action) => action.id === 'pending',
    );
    expect(sinAlerta?.alert).toBe(false);
  });
});
