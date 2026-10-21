export interface PriceListSortable {
  code?: string | null;
  name?: string | null;
  sort_order?: number | null;
}

/**
 * Ordena las listas de precio priorizando su `sort_order` programado.
 * Si dos listas tienen el mismo `sort_order` (o no tienen uno explícito),
 * se ordenan de forma natural por su `code` (P1, P2, P3...), usando `name`
 * como respaldo. No modifica el arreglo original.
 */
export function sortPriceListsByCode<T extends PriceListSortable>(lists: readonly T[]): T[] {
  return [...lists].sort((a, b) => {
    const orderA = a.sort_order ?? 0;
    const orderB = b.sort_order ?? 0;
    if (orderA !== orderB) {
      return orderA - orderB;
    }

    const left = String(a.code ?? a.name ?? '').toUpperCase();
    const right = String(b.code ?? b.name ?? '').toUpperCase();

    return left.localeCompare(right, undefined, { numeric: true });
  });
}
