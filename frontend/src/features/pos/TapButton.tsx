import type { ButtonHTMLAttributes, ReactNode } from 'react';

import { usePosTap } from './usePosTap';

export interface TapButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  onPress?: () => void;
  children?: ReactNode;
}

/**
 * Boton del POS con deteccion de tap robusta para tablets (Android).
 *
 * `onPress` se dispara al primer toque tactil (pointerdown inmediato, que
 * cubre el pointercancel de Android al cerrar el teclado) y tambien via
 * use-gesture y el onClick de respaldo. La deduplicacion interna evita que
 * la accion se ejecute mas de una vez por toque.
 */
export function TapButton({ onPress, children, onClick, disabled, ...rest }: TapButtonProps) {
  const { bind, fire } = usePosTap(() => {
    if (onPress && !disabled) onPress();
  }, !disabled);

  return (
    <button
      type="button"
      {...bind()}
      onClick={(event) => {
        fire();
        onClick?.(event);
      }}
      disabled={disabled}
      {...rest}
    >
      {children}
    </button>
  );
}
