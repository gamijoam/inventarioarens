const KEY_PREFIX = 'inventory_pinned_categories_';

function storageKey(tenantId: number | null | undefined): string {
  return `${KEY_PREFIX}${tenantId ?? 'none'}`;
}

/**
 * Categorias "fijadas" por el usuario para mostrarlas a primera vista como
 * filtros rapidos en el Centro de Inventario. Persistido en localStorage por
 * empresa (tenantId).
 */
export function loadPinnedCategories(tenantId: number | null | undefined): number[] {
  try {
    const raw = localStorage.getItem(storageKey(tenantId));
    if (!raw) return [];
    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((value): value is number => typeof value === 'number');
  } catch {
    return [];
  }
}

export function savePinnedCategories(
  tenantId: number | null | undefined,
  categoryIds: number[],
): void {
  try {
    localStorage.setItem(storageKey(tenantId), JSON.stringify(categoryIds));
  } catch {
    // localStorage no disponible (privado, SSR, etc.)
  }
}
