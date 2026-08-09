/**
 * Soporte tactil especifico del cliente POS.
 *
 * En tablets, el teclado virtual y el double-tap zoom causan dos problemas:
 * 1. Un input con `autoFocus` abre el teclado y el PRIMER tap en la UI solo
 *    lo cierra (no dispara el click del elemento de abajo).
 * 2. Sin `touch-action: manipulation` hay ~300ms de delay (doble-tap para
 *    zoom) y el primer tap puede interpretarse como scroll/hover.
 *
 * Este modulo expone:
 * - Helpers puros para ajustar el viewport SOLO en el bundle POS.
 * - `installPosTouchTap`: listener global que hace que CUALQUIER boton del
 *   POS responda al primer toque tactil (cubre todos los botones, no solo
 *   los que tienen onPointerDown explicito).
 */
import type React from 'react';

export const POS_TOUCH_CLASS = 'pos-touch-mode';

/**
 * Devuelve la cadena del meta viewport que debe usar el cliente POS.
 * Desactiva el zoom por pinch/double-tap para que el primer toque no se
 * consuma en zoom y los botones respondan al instante.
 */
export function posViewportContent(): string {
  return 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover';
}

/**
 * Aplica el viewport tactil del POS al meta[viewport] actual del documento.
 * No-op si no hay meta viewport (p. ej. en tests jsdom sin head o si el
 * bundle se sirve sin el meta).
 */
export function applyPosViewport(
  documentRef: Pick<Document, 'querySelector'> = document,
): boolean {
  const meta = documentRef.querySelector('meta[name="viewport"]');
  if (!meta) return false;
  meta.setAttribute('content', posViewportContent());
  return true;
}

/**
 * Marca el <body> con la clase `pos-touch-mode` para que el CSS aplique
 * `touch-action: manipulation` y las interacciones tactiles respondan sin
 * delay ni zoom accidental.
 */
export function enablePosTouchMode(
  documentRef: Pick<Document, 'body'> = document,
): void {
  documentRef.body.classList.add(POS_TOUCH_CLASS);
}

/**
 * Distancia maxima (px) de movimiento permitida para que un toque se
 * considere un TAP y no un scroll/drag.
 */
const TAP_SLOP_PX = 10;

/** Ventana (ms) en la que se suprime el click sintetico posterior al tap. */
const SUPPRESS_CLICK_MS = 350;

const INTERACTIVE_SELECTOR = 'button, [role="button"], a, label, select, input[type="checkbox"], input[type="radio"]';

function closestInteractive(target: EventTarget | null): Element | null {
  if (!(target instanceof Element)) return null;
  return target.closest(INTERACTIVE_SELECTOR);
}

function isTouchPointerType(event: { pointerType?: string }): boolean {
  return event.pointerType === 'touch' || event.pointerType === 'pen';
}

/**
 * Decide si el POS debe auto-focalizar el input de busqueda al volver al
 * panel principal. En escritorio conviene (el usuario escribe directo);
 * en tablets/tactil NO: re-focalizar el input abre el teclado virtual,
 * cambia el layout y pierde la accion del tap que acaba de ocurrir.
 */
export function shouldAutoFocusSearch(isTouchDevice: boolean): boolean {
  return !isTouchDevice;
}

/**
 * Detecta si el dispositivo actual soporta entrada tactil primaria.
 * No-op seguro en entornos sin navigator.
 */
export function isTouchPrimaryDevice(
  navigatorRef: { maxTouchPoints?: number; userAgent?: string } = navigator,
): boolean {
  if (typeof navigatorRef.maxTouchPoints === 'number' && navigatorRef.maxTouchPoints > 0) {
    return true;
  }
  return /Android|iPhone|iPad|iPod|Tablet|Mobile/i.test(navigatorRef.userAgent ?? '');
}

export interface TouchTapHandlers {
  onTouchStart?: React.TouchEventHandler<HTMLElement>;
  onTouchEnd?: React.TouchEventHandler<HTMLElement>;
}

/**
 * Handlers nativos de touch para botones dentro de contenedores con scroll
 * (grid de productos, sugerencias, metodos de pago).
 *
 * Por que no basta con click ni pointer events: en tablets, tocar un boton
 * que vive en un `overflow: auto` hace que el navegador inicie un scroll
 * gesture; el `pointerup` puede no entregarse o el `click` se pierde.
 * `onTouchEnd` SIEMPRE llega, asi que aqui guardamos la posicion del
 * `touchstart` y, si el dedo no se movio mas de `TAP_SLOP_PX`, disparamos
 * la accion y hacemos `preventDefault()` para que el click sintetico
 * posterior del navegador NO duplique la accion.
 *
 * Uso: esparcir `{ onTouchStart, onTouchEnd }` sobre el elemento, ademas
 * del `onClick` normal (que cubre mouse/teclado).
 */
export function touchTapHandlers(
  action: () => void,
  enabled = true,
): TouchTapHandlers {
  let startY = 0;
  let startX = 0;

  return {
    onTouchStart(event: React.TouchEvent<HTMLElement>): void {
      const touch = event.touches[0];
      if (!touch) return;
      startX = touch.clientX;
      startY = touch.clientY;
    },
    onTouchEnd(event: React.TouchEvent<HTMLElement>): void {
      if (!enabled) return;
      const changed = event.changedTouches[0];
      if (!changed) return;
      const dx = changed.clientX - startX;
      const dy = changed.clientY - startY;
      if (Math.hypot(dx, dy) > TAP_SLOP_PX) return;
      // Evita que el click sintetico posterior duplique la accion.
      event.preventDefault();
      action();
    },
  };
}

/**
 * Instala un listener global que hace que cualquier boton/enlace del POS
 * responda al PRIMER tap tactil, sin doble disparo.
 *
 * - pointerdown: recuerda el elemento y su posicion.
 * - pointerup: si el toque no se movio mas de `TAP_SLOP_PX`, dispara
 *   `click()` manualmente y marca una ventana de supresion para que el
 *   click sintetico del navegador no vuelva a disparar la accion.
 *
 * Para mouse no hace nada: los botones siguen funcionando con el click
 * normal. Retorna una funcion para desinstalar el listener.
 */
export function installPosTouchTap(
  documentRef: Pick<Document, 'addEventListener' | 'removeEventListener'> = document,
): () => void {
  let pending: { element: Element; x: number; y: number } | null = null;
  let suppressClickUntil = 0;

  function onPointerDown(event: PointerEvent): void {
    if (!isTouchPointerType(event)) return;
    const element = closestInteractive(event.target);
    if (!element) return;
    // Evita el blur del input/teclado virtual y el scroll/drag por defecto
    // que el navegador inicia al tocar. Sin esto, el PRIMER tap cuando el
    // teclado esta abierto (busqueda) solo cierra el teclado, el layout
    // cambia y el pointerup llega con coordenadas distintas -> se pierde.
    event.preventDefault();
    pending = { element, x: event.clientX, y: event.clientY };
  }

  function onPointerUp(event: PointerEvent): void {
    if (!isTouchPointerType(event) || !pending) return;
    const { element, x, y } = pending;
    pending = null;

    const dx = event.clientX - x;
    const dy = event.clientY - y;
    if (Math.hypot(dx, dy) > TAP_SLOP_PX) return;

    if (!element.isConnected) return;
    suppressClickUntil = Date.now() + SUPPRESS_CLICK_MS;
    const synthetic = new MouseEvent('click', {
      bubbles: true,
      cancelable: true,
      view: undefined,
    });
    // Marcamos el click que disparamos para no suprimirlo nosotros mismos.
    (synthetic as MouseEvent & { __posTap?: boolean }).__posTap = true;
    element.dispatchEvent(synthetic);
  }

  function onClick(event: MouseEvent): void {
    // Ignorar el click sintetico que el navegador emite tras el tap
    // tactil, pero NO nuestro propio click de confirmacion.
    if (Date.now() >= suppressClickUntil) return;
    if ((event as MouseEvent & { __posTap?: boolean }).__posTap) return;
    event.stopImmediatePropagation();
    event.preventDefault();
  }

  documentRef.addEventListener('pointerdown', onPointerDown, true);
  documentRef.addEventListener('pointerup', onPointerUp, true);
  documentRef.addEventListener('click', onClick, true);

  return () => {
    documentRef.removeEventListener('pointerdown', onPointerDown, true);
    documentRef.removeEventListener('pointerup', onPointerUp, true);
    documentRef.removeEventListener('click', onClick, true);
  };
}
