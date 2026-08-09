import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  POS_TOUCH_CLASS,
  applyPosViewport,
  enablePosTouchMode,
  installPosTouchTap,
  isTouchPrimaryDevice,
  posViewportContent,
  shouldAutoFocusSearch,
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
    currentTarget?: HTMLElement;
  };
  type MockHandlers = {
    onTouchStart?: (event: MockTouchEvent) => void;
    onTouchMove?: (event: MockTouchEvent) => void;
    onTouchEnd?: (event: MockTouchEvent) => void;
    onTouchCancel?: (event: MockTouchEvent) => void;
  };

  function touchEvent(
    type: 'start' | 'move' | 'end',
    x: number,
    y: number,
    currentTarget?: HTMLElement,
  ): MockTouchEvent {
    const touch = { clientX: x, clientY: y };
    return {
      touches: type === 'end' ? [] : [touch],
      changedTouches: type === 'end' ? [touch] : [],
      defaultPrevented: false,
      preventDefault() {
        this.defaultPrevented = true;
      },
      currentTarget,
    };
  }

  it('dispara la accion en un tap sin movimiento y previene el click sintetico', () => {
    const action = vi.fn();
    const handlers = touchTapHandlers(action) as MockHandlers;

    const end = touchEvent('end', 5, 5);
    handlers.onTouchStart?.(touchEvent('start', 5, 5));
    handlers.onTouchEnd?.(end);

    expect(action).toHaveBeenCalledTimes(1);
    expect(end.defaultPrevented).toBe(true);
  });

  it('cierra el teclado virtual en touchstart para que Android no cancele el primer tap', () => {
    const input = document.createElement('input');
    document.body.appendChild(input);
    const buttonEl = document.createElement('button');
    document.body.appendChild(buttonEl);
    // jsdom no mantiene focus de verdad; simulamos el blur spy sobre el
    // activeElement del documento en el momento del touchstart.
    const blurSpy = vi.fn();
    vi.spyOn(document, 'activeElement', 'get').mockReturnValue(input);
    input.blur = blurSpy;
    const action = vi.fn();
    const handlers = touchTapHandlers(action) as MockHandlers;

    handlers.onTouchStart?.(touchEvent('start', 5, 5, buttonEl));
    handlers.onTouchEnd?.(touchEvent('end', 5, 5, buttonEl));

    expect(blurSpy).toHaveBeenCalled();
    expect(action).toHaveBeenCalledTimes(1);
    vi.restoreAllMocks();
    input.remove();
    buttonEl.remove();
  });

  it('NO dispara la accion si el toque se movio (scroll/drag del contenedor)', () => {
    const action = vi.fn();
    const handlers = touchTapHandlers(action) as MockHandlers;

    handlers.onTouchStart?.(touchEvent('start', 5, 5));
    handlers.onTouchMove?.(touchEvent('move', 90, 70));
    handlers.onTouchEnd?.(touchEvent('end', 90, 70));

    expect(action).not.toHaveBeenCalled();
  });

  it('NO dispara la accion si Android cancela el toque (touchcancel) al iniciar scroll', () => {
    const action = vi.fn();
    const handlers = touchTapHandlers(action) as MockHandlers;

    handlers.onTouchStart?.(touchEvent('start', 5, 5));
    handlers.onTouchCancel?.(touchEvent('end', 90, 70));

    expect(action).not.toHaveBeenCalled();
  });

  it('no dispara la accion cuando el boton esta deshabilitado', () => {
    const action = vi.fn();
    const handlers = touchTapHandlers(action, false) as MockHandlers;

    handlers.onTouchStart?.(touchEvent('start', 5, 5));
    handlers.onTouchEnd?.(touchEvent('end', 5, 5));

    expect(action).not.toHaveBeenCalled();
  });
});

describe('autofocus y deteccion tactil', () => {
  it('no auto-focaliza el buscador en dispositivos tactiles (evita reabrir teclado)', () => {
    expect(shouldAutoFocusSearch(true)).toBe(false);
    expect(shouldAutoFocusSearch(false)).toBe(true);
  });

  it('detecta dispositivos tactiles por maxTouchPoints', () => {
    expect(isTouchPrimaryDevice({ maxTouchPoints: 10, userAgent: 'x' })).toBe(true);
  });

  it('detecta tablets/moviles por user agent', () => {
    expect(isTouchPrimaryDevice({ maxTouchPoints: 0, userAgent: 'Android Tablet' })).toBe(true);
    expect(isTouchPrimaryDevice({ maxTouchPoints: 0, userAgent: 'iPad' })).toBe(true);
    expect(isTouchPrimaryDevice({ maxTouchPoints: 0, userAgent: 'Mozilla/5.0 (Windows NT 10.0)' })).toBe(false);
  });
});
