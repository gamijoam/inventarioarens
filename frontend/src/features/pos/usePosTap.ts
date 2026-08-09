import { useDrag } from '@use-gesture/react';
import { useRef } from 'react';

export interface PosTapHandlers {
  onPointerDown?: (event: unknown) => void;
  onPointerMove?: (event: unknown) => void;
  onPointerUp?: (event: unknown) => void;
  onPointerCancel?: (event: unknown) => void;
  onLostPointerCapture?: (event: unknown) => void;
}

export interface UsePosTapResult {
  bind: () => PosTapHandlers;
  /**
   * Ejecuta la accion deduplicada: el onClick nativo (respaldo mouse/teclado)
   * debe llamar a esta funcion para no duplicar el disparo del pointerdown
   * tactil ni el tap de use-gesture.
   */
  fire: (source?: 'touch' | 'click' | 'gesture') => void;
}

/**
 * Hook de tap tactil para el POS.
 *
 * Usa `useDrag` de use-gesture con `filterTaps` como deteccion primaria.
 *
 * IMPORTANTE (auditoria 2026-08-09): en tablets Android, cuando el teclado
 * virtual esta abierto, el PRIMER toque cierra el teclado y el navegador
 * emite `pointercancel`/`touchcancel` ANTES del `pointerup`. use-gesture
 * (al igual que cualquier deteccion que espera el release del toque) no
 * confirma el tap en ese caso y la accion se pierde.
 *
 * Por eso, ademas de use-gesture, disparamos la accion de forma inmediata
 * en `pointerdown` (que SIEMPRE llega antes del cancel) cuando es un puntero
 * tactil. Esto cubre el caso del teclado abierto.
 *
 * `fire()` deduplica disparos repetidos dentro de una ventana corta
 * (Android puede llegar a emitir pointerdown + click nativo), evitando que
 * la accion se ejecute mas de una vez por toque.
 */
export function usePosTap(onTap: () => void, enabled = true): UsePosTapResult {
  const lastTouchFiredAt = useRef(0);
  const lastGestureFiredAt = useRef(0);
  const DEDUP_MS = 400;
  const fire = (source: 'touch' | 'click' | 'gesture' = 'gesture'): void => {
    const now = Date.now();
    if (source === 'click') {
      // Android can emit a native click after the immediate touch action.
      if (now - lastTouchFiredAt.current < DEDUP_MS) return;
    } else if (source === 'touch') {
      if (now - lastTouchFiredAt.current < DEDUP_MS) return;
      lastTouchFiredAt.current = now;
    } else {
      if (now - lastTouchFiredAt.current < DEDUP_MS) return;
      if (now - lastGestureFiredAt.current < DEDUP_MS) return;
      lastGestureFiredAt.current = now;
    }
    if (enabled) onTap();
  };

  const bind = useDrag(
    ({ tap, event }) => {
      if (!tap || !enabled) return;
      event?.preventDefault?.();
      fire('gesture');
    },
    {
      filterTaps: true,
      threshold: 6,
      pointer: { touch: true },
    },
  );

  const baseBind = bind as () => PosTapHandlers;

  return {
    fire,
    bind: () => {
      const handlers = baseBind();
      const pointerDown = handlers.onPointerDown;
      return {
        ...handlers,
        onPointerDown(event: unknown): void {
          const pointer = event as { pointerType?: string };
          if (pointer.pointerType === 'touch' || pointer.pointerType === 'pen') {
            fire('touch');
          }
          pointerDown?.(event);
        },
      };
    },
  };
}
