import type { ButtonHTMLAttributes, ReactNode } from 'react';

import { usePosTap } from './usePosTap';

export interface TapButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  onPress?: () => void;
  children?: ReactNode;
}

/**
 * Boton del POS con deteccion de tap robusta para tablets (Android).
 *
 * Usa use-gesture (`useDrag` + `filterTaps`) para detectar el toque sin
 * movimiento de forma fiable incluso con el teclado virtual abierto o en
 * contenedores con scroll. `onPress` se dispara en el tap; `onClick` sigue
 * disponible para mouse/teclado y como respaldo.
 */
export function TapButton({ onPress, children, onClick, disabled, ...rest }: TapButtonProps) {
  const bind = usePosTap(() => {
    if (onPress && !disabled) onPress();
  }, !disabled);

  return (
    <button
      type="button"
      {...bind()}
      onClick={(event) => {
        if (onPress && !disabled) onPress();
        onClick?.(event);
      }}
      disabled={disabled}
      {...rest}
    >
      {children}
    </button>
  );
}
