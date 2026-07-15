/**
 * Query keys para TanStack Query del modulo de Proveedores.
 */
export const supplierKeys = {
  all: ['suppliers'] as const,
  lists: () => [...supplierKeys.all, 'list'] as const,
  list: (filters: Record<string, unknown>) => [...supplierKeys.lists(), filters] as const,
  details: () => [...supplierKeys.all, 'detail'] as const,
  detail: (id: number) => [...supplierKeys.details(), id] as const,
};
