const POS_SHOW_VES_CARDS_KEY = 'pos_show_ves_cards';

/**
 * Preferencia del POS: mostrar el valor en Bs (VES) en las tarjetas del
 * catalogo, junto al precio en USD. Persistida en localStorage.
 * Por defecto: true (comportamiento actual).
 */
export function loadShowVesOnCards(): boolean {
  try {
    const saved = localStorage.getItem(POS_SHOW_VES_CARDS_KEY);
    if (saved === 'false') return false;
    if (saved === 'true') return true;
  } catch {
    // localStorage no disponible (SSR, privado, etc.)
  }

  return true;
}

export function saveShowVesOnCards(value: boolean): void {
  try {
    localStorage.setItem(POS_SHOW_VES_CARDS_KEY, value ? 'true' : 'false');
  } catch {
    // ignore
  }
}
