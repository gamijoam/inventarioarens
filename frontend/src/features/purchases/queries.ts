/**
 * Query keys centralizados para TanStack Query del modulo de Compras.
 * Reflejan la jerarquia: invalidar ['purchases', 'list'] invalida todos
 * los listados, etc.
 */
export const purchaseKeys = {
  all: ['purchases'] as const,
  lists: () => [...purchaseKeys.all, 'list'] as const,
  list: (filters: Record<string, unknown>) => [...purchaseKeys.lists(), filters] as const,
  details: () => [...purchaseKeys.all, 'detail'] as const,
  detail: (id: number) => [...purchaseKeys.details(), id] as const,
};
