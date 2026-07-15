/**
 * Query keys centralizados para TanStack Query.
 * Reflejan la jerarquia: invalidar ['products', 'list'] invalida todos los listados.
 */
export const productKeys = {
  all: ['products'] as const,
  lists: () => [...productKeys.all, 'list'] as const,
  list: (filters: Record<string, unknown>) => [...productKeys.lists(), filters] as const,
  details: () => [...productKeys.all, 'detail'] as const,
  detail: (id: number) => [...productKeys.details(), id] as const,
  serials: (id: number) => [...productKeys.all, 'serials', id] as const,
  movements: (id: number) => [...productKeys.all, 'movements', id] as const,
  stockStatus: (id: number) => [...productKeys.all, 'stock-status', id] as const,
  reorder: () => [...productKeys.all, 'reorder'] as const,
  alertsSummary: () => [...productKeys.all, 'alerts-summary'] as const,
  prices: (id: number) => [...productKeys.all, 'prices', id] as const,
  priceHistory: (id: number) => [...productKeys.all, 'price-history', id] as const,
  audits: (id: number) => [...productKeys.all, 'audits', id] as const,
  stockByWarehouse: (id: number) => [...productKeys.all, 'stock-by-warehouse', id] as const,
};

export const catalogKeys = {
  all: ['catalog'] as const,
  brands: () => [...catalogKeys.all, 'brands'] as const,
  brand: (id: number) => [...catalogKeys.brands(), id] as const,
  categories: () => [...catalogKeys.all, 'categories'] as const,
  categoryTree: () => [...catalogKeys.categories(), 'tree'] as const,
  category: (id: number) => [...catalogKeys.categories(), id] as const,
  tags: () => [...catalogKeys.all, 'tags'] as const,
  tag: (id: number) => [...catalogKeys.tags(), id] as const,
  warrantyPolicies: () => [...catalogKeys.all, 'warranty-policies'] as const,
  exchangeRateTypes: () => [...catalogKeys.all, 'exchange-rate-types'] as const,
  exchangeRateType: (id: number) => [...catalogKeys.exchangeRateTypes(), id] as const,
  exchangeRates: () => [...catalogKeys.all, 'exchange-rates'] as const,
  exchangeRate: (id: number) => [...catalogKeys.exchangeRates(), id] as const,
  priceLists: () => [...catalogKeys.all, 'price-lists'] as const,
  warehouses: () => [...catalogKeys.all, 'warehouses'] as const,
  branches: () => [...catalogKeys.all, 'branches'] as const,
};