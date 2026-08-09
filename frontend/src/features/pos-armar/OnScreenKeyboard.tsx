import { KEYBOARD_ROWS, type KeyAction } from './armOrderLogic';

export interface OnScreenKeyboardProps {
  onKey: (action: KeyAction) => void;
  disabled?: boolean;
}

/**
 * Teclado on-screen del POS tactil "Armar orden".
 *
 * Es una grilla de botones grandes pensada para tablet: NO abre el teclado
 * del sistema (no hay input), por lo que el tap nunca se pierde por el
 * cierre del teclado virtual de Android. Cada pulsacion dispara `onKey`
 * con la accion de edicion correspondiente.
 */
export function OnScreenKeyboard({ onKey, disabled }: OnScreenKeyboardProps) {
  return (
    <div className="grid gap-2" aria-label="Teclado en pantalla" role="group">
      {KEYBOARD_ROWS.map((row, rowIndex) => (
        <div key={rowIndex} className="flex gap-2">
          {row.map((key) => {
            const wide = key === 'ESPACIO' || key === 'BORRAR';
            return (
              <button
                key={key}
                type="button"
                disabled={disabled}
                data-key={key}
                data-testid={`key-${key}`}
                onClick={() => onKey(KEYBOARD_ACTION(key))}
                className={`bg-surface text-text-primary hover:bg-primary/10 active:bg-primary/20 border-border h-14 min-w-0 flex-1 rounded-xl border text-lg font-semibold shadow-sm transition-colors ${
                  wide ? 'text-sm' : ''
                }`}
              >
                {key}
              </button>
            );
          })}
        </div>
      ))}
    </div>
  );
}

function KEYBOARD_ACTION(key: string): KeyAction {
  if (key === 'ESPACIO') return { type: 'space' };
  if (key === 'BORRAR') return { type: 'backspace' };
  return { type: 'char', char: key };
}
