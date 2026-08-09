/**
 * Ayudas puras para el contador y las alertas de ordenes pendientes del POS.
 *
 * La cajera necesita saber si LLEGARON ordenes nuevas (armadas por un
 * vendedor en otra terminal) sin tener que abrir el panel Pendientes cada
 * vez. Estos helpers comparan snapshots del listado para detectar ids
 * nuevos y devolver el conteo.
 */

export interface PendingOrderLike {
  id: number;
}

/**
 * Devuelve los ids de ordenes que NO estaban en `previousIds`.
 * Se usa tras cada polling para disparar la alerta "orden nueva".
 */
export function newPendingOrderIds(
  previousIds: number[],
  orders: PendingOrderLike[],
): number[] {
  const seen = new Set(previousIds);
  return orders
    .map((order) => order.id)
    .filter((id) => !seen.has(id));
}

/**
 * Conteo de ordenes pendientes visibles.
 */
export function countPendingOrders(orders: PendingOrderLike[]): number {
  return orders.length;
}

/**
 * True si hay ordenes pendientes (cualquier cantidad) para resaltar el boton.
 */
export function hasPendingOrders(orders: PendingOrderLike[]): boolean {
  return orders.length > 0;
}
