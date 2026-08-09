import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  POS_TOUCH_CLASS,
  applyPosViewport,
  enablePosTouchMode,
  installPosTouchTap,
  posViewportContent,
  touchTapHandlers,
} from '../touchSupport';

function createMeta(): HTMLMetaElement {
  const meta = document.createElement('meta');
  meta.setAttribute('name', 'viewport');
  document.head.appendChild(meta);
  return meta;
}

function pointerEvent(type: string, pointerType: string, x: number, y: number): Event {
  // jsdom no define PointerEvent; lo emulamos con un MouseEvent y las
  // propiedades que el listener global lee (pointerType, clientX/Y).
  const event = new MouseEvent(type, { bubbles: true, cancelable: true });
  Object.defineProperties(event, {
    pointerType: { value: pointerType },
    clientX: { value: x },
    clientY: { value: y },
  });
  return event;
}

describe('pos touchSupport', () => {
  let cleanup: (() => void) | null = null;

  afterEach(() => {
    cleanup?.();
    cleanup = null;
    document.head.querySelectorAll('meta[name="viewport"]').forEach((meta) => meta.remove());
    document.body.classList.remove(POS_TOUCH_CLASS);
  });

  it('genera un viewport tactil sin zoom para el POS', () => {
    expect(posViewportContent()).toContain('user-scalable=no');
    expect(posViewportContent()).toContain('maximum-scale=1.0');
    expect(posViewportContent()).toContain('width=device-width');
  });

  it('aplica el viewport tactil al meta actual', () => {
    const meta = createMeta();
    const applied = applyPosViewport(document);
    expect(applied).toBe(true);
    expect(meta.getAttribute('content')).toBe(posViewportContent());
  });

  it('no falla si no existe el meta viewport', () => {
    expect(applyPosViewport(document)).toBe(false);
  });

  it('agrega la clase pos-touch-mode al body', () => {
    enablePosTouchMode(document);
    expect(document.body.classList.contains(POS_TOUCH_CLASS)).toBe(true);
  });
});

describe('installPosTouchTap', () => {
  let cleanup: (() => void) | null = null;
  let button: HTMLButtonElement;

  beforeEach(() => {
    button = document.createElement('button');
    button.type = 'button';
    button.textContent = 'Agregar';
    document.body.appendChild(button);
    cleanup = installPosTouchTap(document);
  });

  afterEach(() => {
    cleanup?.();
    cleanup = null;
    button.remove();
  });

  it('dispara la accion en el primer tap tactil sin esperar un click nativo', () => {
    const onClick = vi.fn();
    button.addEventListener('click', onClick);

    button.dispatchEvent(pointerEvent('pointerdown', 'touch', 10, 10));
    button.dispatchEvent(pointerEvent('pointerup', 'touch', 10, 10));

    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('no dispara dos veces por el click sintetico posterior', () => {
    const onClick = vi.fn();
    button.addEventListener('click', onClick);

    button.dispatchEvent(pointerEvent('pointerdown', 'touch', 10, 10));
    button.dispatchEvent(pointerEvent('pointerup', 'touch', 10, 10));
    // El navegador emite un click sintetico justo despues del pointerup.
    button.dispatchEvent(pointerEvent('click', 'touch', 10, 10));

    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('no dispara la accion si el toque se movio (scroll/drag)', () => {
    const onClick = vi.fn();
    button.addEventListener('click', onClick);

    button.dispatchEvent(pointerEvent('pointerdown', 'touch', 10, 10));
    button.dispatchEvent(pointerEvent('pointerup', 'touch', 80, 60));

    expect(onClick).not.toHaveBeenCalled();
  });

  it('ignora eventos de mouse (el click normal sigue funcionando)', () => {
    const onClick = vi.fn();
    button.addEventListener('click', onClick);

    button.dispatchEvent(pointerEvent('pointerdown', 'mouse', 10, 10));
    button.dispatchEvent(pointerEvent('pointerup', 'mouse', 10, 10));

    expect(onClick).not.toHaveBeenCalled();
  });

  it('previene el blur del input en pointerdown para no perder el primer tap cuando el teclado esta abierto', () => {
    const input = document.createElement('input');
    document.body.appendChild(input);
    const onClick = vi.fn();
    button.addEventListener('click', onClick);

    let defaultPrevented = false;
    const down = pointerEvent('pointerdown', 'touch', 10, 10);
    down.preventDefault = () => {
      defaultPrevented = true;
    };
    button.dispatchEvent(down);
    button.dispatchEvent(pointerEvent('pointerup', 'touch', 10, 10));

    expect(defaultPrevented).toBe(true);
    expect(onClick).toHaveBeenCalledTimes(1);
    input.remove();
  });
});

describe('touchTapHandlers', () => {
  type TouchLike = { clientX: number; clientY: number };
  type MockTouchEvent = {
    touches: TouchLike[];
    changedTouches: TouchLike[];
    defaultPrevented: boolean;
    preventDefault: () => void;
  };

  function touchEvent(type: 'start' | 'end', x: number, y: number): MockTouchEvent {
    const touch = { clientX: x, clientY: y };
    return {
      touches: type === 'start' ? [touch] : [],
      changedTouches: type === 'end' ? [touch] : [],
      defaultPrevented: false,
      preventDefault() {
        this.defaultPrevented = true;
      },
    };
  }

  it('dispara la accion en un tap sin movimiento y previene el click sintetico', () => {
    const action = vi.fn();
    const handlers = touchTapHandlers(action) as {
      onTouchStart?: (event: MockTouchEvent) => void;
      onTouchEnd?: (event: MockTouchEvent) => void;
    };

    const end = touchEvent('end', 5, 5);
    handlers.onTouchStart?.(touchEvent('start', 5, 5));
    handlers.onTouchEnd?.(end);

    expect(action).toHaveBeenCalledTimes(1);
    expect(end.defaultPrevented).toBe(true);
  });

  it('NO dispara la accion si el toque se movio (scroll/drag del contenedor)', () => {
    const action = vi.fn();
    const handlers = touchTapHandlers(action) as {
      onTouchStart?: (event: MockTouchEvent) => void;
      onTouchEnd?: (event: MockTouchEvent) => void;
    };

    handlers.onTouchStart?.(touchEvent('start', 5, 5));
    handlers.onTouchEnd?.(touchEvent('end', 90, 70));

    expect(action).not.toHaveBeenCalled();
  });

  it('no dispara la accion cuando el boton esta deshabilitado', () => {
    const action = vi.fn();
    const handlers = touchTapHandlers(action, false) as {
      onTouchStart?: (event: MockTouchEvent) => void;
      onTouchEnd?: (event: MockTouchEvent) => void;
    };

    handlers.onTouchStart?.(touchEvent('start', 5, 5));
    handlers.onTouchEnd?.(touchEvent('end', 5, 5));

    expect(action).not.toHaveBeenCalled();
  });
});
