/**
 * Logica pura de la pantalla tactil "Armar orden".
 *
 * A diferencia del POS normal, esta pantalla NO usa el teclado del sistema:
 * usa un teclado on-screen propio (botones grandes) para que en tablets
 * Android el tap no se pierda por el cierre del teclado virtual.
 *
 * Este modulo contiene solo funciones puras testeables: construccion del
 * layout de teclas, aplicacion de pulsaciones y normalizacion de la
 * busqueda.
 */

export const KEYBOARD_ROWS: readonly string[][] = [
  ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
  ['Q', 'W', 'E', 'R', 'T', 'Y', 'U', 'I', 'O', 'P'],
  ['A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L', 'Ñ'],
  ['Z', 'X', 'C', 'V', 'B', 'N', 'M', 'ESPACIO', 'BORRAR'],
];

export type KeyAction = { type: 'char'; char: string } | { type: 'space' } | { type: 'backspace' };

/** Interpreta una tecla del teclado on-screen en una accion de edicion. */
export function keyAction(key: string): KeyAction {
  const normalized = key.toUpperCase();
  if (normalized === 'ESPACIO') return { type: 'space' };
  if (normalized === 'BORRAR') return { type: 'backspace' };
  return { type: 'char', char: normalized };
}

/** Aplica una accion de teclado al valor de busqueda actual. */
export function applyKey(value: string, action: KeyAction): string {
  switch (action.type) {
    case 'space':
      return `${value} `.replace(/ +$/, ' ');
    case 'backspace':
      return value.slice(0, -1);
    case 'char':
      return `${value}${action.char}`;
  }
}

/**
 * Normaliza la busqueda: quita TODOS los espacios y pasa a minusculas.
 * Devuelve la cadena con la que se consulta el backend (SKU, codigo de
 * barras y nombre se buscan sin espacios).
 */
export function normalizeSearch(value: string): string {
  return value.replace(/\s+/g, '').toLowerCase();
}

/**
 * True si la busqueda tiene minimo 2 caracteres utiles (requisito del
 * endpoint de productos).
 */
export function canSearch(value: string): boolean {
  return normalizeSearch(value).length >= 2;
}

/** Formatea un monto en USD con 2 decimales. */
export function money(value: number): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value);
}
