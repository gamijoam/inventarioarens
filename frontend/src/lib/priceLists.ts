export interface PriceListSortable {
  code?: string | null;
  name?: string | null;
}

/**
 * Ordena las listas de precio de forma natural por su `code` (P1, P2, P3...),
 * usando el `name` como respaldo. No modifica el arreglo original.
 *
 * La API devuelve las listas por id (en Super Leopard: P3=4, P1=5, P2=6), lo
 * que las mostraba como P3, P1, P2. El POS y el Centro de Inventario usan esta
 * funcion para mostrarlas P1, P2, P3.
 */
export function sortPriceListsByCode<T extends PriceListSortable>(lists: readonly T[]): T[] {
  return [...lists].sort((a, b) => {
    const left = String(a.code ?? a.name ?? '').toUpperCase();
    const right = String(b.code ?? b.name ?? '').toUpperCase();

    return left.localeCompare(right, undefined, { numeric: true });
  });
}
