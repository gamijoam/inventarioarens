import { describe, expect, it, vi } from 'vitest';

import { triggerPosPointerAction } from '../PosTerminal';

describe('triggerPosPointerAction', () => {
  it('ejecuta la accion y suprime el click sintetico en touch', () => {
    const action = vi.fn();
    const preventDefault = vi.fn();
    const handled = triggerPosPointerAction(
      { pointerType: 'touch', preventDefault },
      action,
    );

    expect(handled).toBe(true);
    expect(action).toHaveBeenCalledOnce();
    expect(preventDefault).toHaveBeenCalledOnce();
  });

  it('ejecuta la accion y suprime el click en pen (lapiz tactil)', () => {
    const action = vi.fn();
    const preventDefault = vi.fn();
    const handled = triggerPosPointerAction({ pointerType: 'pen', preventDefault }, action);

    expect(handled).toBe(true);
    expect(action).toHaveBeenCalledOnce();
    expect(preventDefault).toHaveBeenCalledOnce();
  });

  it('NO ejecuta la accion para mouse (deja pasar al onClick normal)', () => {
    const action = vi.fn();
    const preventDefault = vi.fn();
    const handled = triggerPosPointerAction({ pointerType: 'mouse', preventDefault }, action);

    expect(handled).toBe(false);
    expect(action).not.toHaveBeenCalled();
    expect(preventDefault).not.toHaveBeenCalled();
  });

  it('NO ejecuta la accion cuando no hay pointerType (evento no puntero)', () => {
    const action = vi.fn();
    const handled = triggerPosPointerAction({}, action);

    expect(handled).toBe(false);
    expect(action).not.toHaveBeenCalled();
  });
});
