/**
 * API del Centro de Inventario.
 * Endpoints:
 *   GET /api/inventory-center/summary       (listado + metricas)
 *   GET /api/inventory-center/products/{id} (detalle completo)
 *   GET /api/inventory-center/products/{id}/serials
 *   GET /api/inventory-center/products/{id}/movements
 *   GET /api/inventory-center/products/{id}/stock-by-warehouse
 */
import { useQuery } from '@tanstack/react-query';

import { getPaginated, getOne } from '@/api/client';
import { useSessionStore } from '@/stores/session';
import {
  type InventoryFilters,
  type Product,
  type ProductDetail,
  type ProductMovement,
  type ProductSerial,
  type ProductStock,
} from './schemas';

export const inventoryKeys = {
  all: ['inventory'] as const,
  lists: () => [...inventoryKeys.all, 'list'] as const,
  list: (filters: InventoryFilters) => [...inventoryKeys.lists(), filters] as const,
  details: () => [...inventoryKeys.all, 'detail'] as const,
  detail: (id: number) => [...inventoryKeys.details(), id] as const,
  serials: (id: number) => [...inventoryKeys.all, 'serials', id] as const,
  movements: (id: number) => [...inventoryKeys.all, 'movements', id] as const,
};

/** Hook: listado de productos con filtros. */
export function useProducts(filters: InventoryFilters) {
  return useQuery({
    queryKey: inventoryKeys.list(filters),
    queryFn: async () => {
      const params = new URLSearchParams();
      params.set('page', String(filters.page));
      params.set('per_page', String(filters.per_page));
      if (filters.search) params.set('search', filters.search);
      if (filters.tracking_type !== 'all') params.set('tracking_type', filters.tracking_type);
      if (filters.stock_status !== 'all') params.set('stock_status', filters.stock_status);
      if (filters.status !== 'all') params.set('status', filters.status);

      return getPaginated<Product>(`/inventory-center/summary?${params.toString()}`);
    },
    placeholderData: (prev) => prev,
  });
}

/** Hook: detalle completo del producto. */
export function useProductDetail(productId: number) {
  return useQuery({
    queryKey: inventoryKeys.detail(productId),
    queryFn: () => getOne<ProductDetail>(`/inventory-center/products/${productId}`),
    enabled: Number.isFinite(productId) && productId > 0,
  });
}

export function useProductSerials(productId: number) {
  return useQuery({
    queryKey: inventoryKeys.serials(productId),
    queryFn: () =>
      getPaginated<ProductSerial>(`/inventory-center/products/${productId}/serials`),
  });
}

export function useProductMovements(productId: number) {
  return useQuery({
    queryKey: inventoryKeys.movements(productId),
    queryFn: () =>
      getOne<{ data: ProductMovement[] }>(`/inventory-center/products/${productId}/movements`).then(
        (r) => r.data,
      ),
  });
}

export function useProductStockByWarehouse(productId: number) {
  return useQuery({
    queryKey: [...inventoryKeys.detail(productId), 'stock'] as const,
    queryFn: () =>
      getOne<{ data: ProductStock[] }>(
        `/inventory-center/products/${productId}/stock-by-warehouse`,
      ).then((r) => r.data),
    enabled: Number.isFinite(productId) && productId > 0,
  });
}

/** Helper para invalidar queries desde mutaciones. */
export function getActiveTenantSlug(): string | undefined {
  return useSessionStore.getState().tenant?.slug;
}