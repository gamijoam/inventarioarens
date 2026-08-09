import { useDrag } from '@use-gesture/react';

export interface PosTapHandlers {
  onPointerDown?: (event: unknown) => void;
  onPointerMove?: (event: unknown) => void;
  onPointerUp?: (event: unknown) => void;
  onPointerCancel?: (event: unknown) => void;
  onLostPointerCapture?: (event: unknown) => void;
}

/**
 * Hook de tap tactil para el POS basado en use-gesture `useDrag`.
 *
 * use-gesture no expone un hook `useTap` standalone, pero `useDrag` con
 * `filterTaps: true` es la forma oficial de detectar un tap (toque sin
 * movimiento) de forma fiable, incluso en tablets Android donde el teclado
 * virtual y los contenedores con scroll cancelan los eventos nativos.
 *
 * El handler recibe `{ tap: true }` solo en un tap valido. Los handlers
 * devueltos se esparcen sobre el elemento (`<button {...bind()}>`).
 */
export function usePosTap(onTap: () => void, enabled = true): () => PosTapHandlers {
  const bind = useDrag(
    ({ tap, event }) => {
      if (!tap || !enabled) return;
      // Suprime el click sintetico posterior para evitar el doble disparo
      // con el onClick de respaldo (mouse).
      event?.preventDefault?.();
      onTap();
    },
    {
      filterTaps: true,
      threshold: 6,
      pointer: { touch: true },
    },
  );

  return bind as () => PosTapHandlers;
}
