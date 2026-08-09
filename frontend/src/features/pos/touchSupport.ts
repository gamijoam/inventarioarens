/**
 * Soporte tactil especifico del cliente POS.
 *
 * En tablets, el teclado virtual y el double-tap zoom causan dos problemas:
 * 1. Un input con `autoFocus` abre el teclado y el PRIMER tap en la UI solo
 *    lo cierra (no dispara el click del elemento de abajo).
 * 2. Sin `touch-action: manipulation` hay ~300ms de delay (doble-tap para
 *    zoom) y el primer tap puede interpretarse como scroll/hover.
 *
 * Este modulo expone helpers puros para ajustar el viewport SOLO en el
 * bundle POS y una utilidad para construir handlers que respondan al
 * pointer/touch de forma inmediata.
 */
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
