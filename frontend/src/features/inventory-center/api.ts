/**
 * API completa del modulo de inventario.
 * Endpoints cubiertos (ver docs/INVENTORY_CATALOG_API.md, INVENTORY_ALERTS_API.md,
 * docs/PRODUCT_IMAGES.md):
 *   - GET    /api/products                                 (listado con filtros)
 *   - GET    /api/products/{id}
 *   - POST   /api/products                                 (crear)
 *   - PATCH  /api/products/{id}                            (actualizar)
 *   - DELETE /api/products/{id}                            (soft delete)
 *   - GET    /api/products/{id}/images                     (galeria)
 *   - POST   /api/products/{id}/images                     (upload)
 *   - PATCH  /api/products/{id}/images/{imageId}           (update alt/primary)
 *   - DELETE /api/products/{id}/images/{imageId}           (soft delete)
 *   - PATCH  /api/products/{id}/images/reorder             (reorder)
 *   - GET    /api/products/{id}/stock-status
 *   - GET    /api/inventory-center/reorder-suggestions
 *   - GET    /api/inventory-center/alerts-summary
 *   - POST   /api/inventory-center/products/bulk-action
 *   - GET    /api/brands
 *   - POST   /api/brands
 *   - GET    /api/categories                               (lista plana)
 *   - GET    /api/categories/tree                     (arbol)
 *   - GET    /api/tags
 *   - GET    /api/warranty-policies
 *   - GET    /api/currency/rate-types
 *   - GET    /api/price-lists?active_only=1
 *   - GET    /api/warehouses
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { api, deleteOne, getMany, getOne, getPaginated, patchOne, postOne } from '@/api/client';
import {
  AlertsSummarySchema,
  BrandSchema,
  BranchSchema,
  CategorySchema,
  ExchangeRateTypeSchema,
  PaginatedProductsSchema,
  PriceListSchema,
  ProductSchema,
  ProductStockSchema,
  ProductStockStatusSchema,
  ReorderSuggestionsResponseSchema,
  TagSchema,
  WarehouseSchema,
  WarrantyPolicySchema,
  type InventoryFilters,
} from './schemas';
import { catalogKeys, productKeys } from './queries';

// =====================================================================
// Hooks de detalle (movimientos, seriales, kardex, stock-by-warehouse).
// Cada hook hace su propia query. La page los combina.
// =====================================================================

export function useProductSerials(productId: number) {
  return useQuery({
    queryKey: productKeys.serials(productId),
    queryFn: async () => {
      // El backend devuelve { data: [...], pagination, filters } (no un array directo).
      const response = (await getOne<unknown>('/inventory-center/products/' + productId + '/serials')) as { data?: unknown[] };
      const items = Array.isArray(response?.data) ? response.data : [];
      const { ProductSerialSchema } = await import('./schemas');
      return z.array(ProductSerialSchema).parse(items);
    },
    enabled: Number.isFinite(productId) && productId > 0,
  });
}

export function useProductMovements(productId: number) {
  return useQuery({
    queryKey: productKeys.movements(productId),
    queryFn: async () => {
      const data = await getOne<{ data: { id: number; warehouse_id: number | null; warehouse_name: string | null; type: string; quantity: string | number; unit_cost: string | null; reference: string | null; created_at: string; user_name: string | null }[] }>(
        `/inventory-center/products/${productId}/movements`,
      );
      return data.data;
    },
    enabled: Number.isFinite(productId) && productId > 0,
  });
}

export function useProductStockByWarehouse(productId: number) {
  return useQuery({
    queryKey: productKeys.stockByWarehouse(productId),
    queryFn: async () => {
      const data = await getOne<{ data: unknown[] }>(
        `/inventory-center/products/${productId}/stock-by-warehouse`,
      );
      // Validamos contra el schema tipado para garantizar shape consistente
      // (available/reserved/damaged). Si el backend cambia, ZodParseError
      // se propaga al UI.
      return z.array(ProductStockSchema).parse(data.data);
    },
    enabled: Number.isFinite(productId) && productId > 0,
  });
}

// =====================================================================
// Productos
// =====================================================================

function toQueryString(filters: Partial<InventoryFilters>): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value == null || value === '' || value === 'all') continue;
    params.set(key, String(value));
  }
  return params.toString();
}

export function useProducts(filters: InventoryFilters, options: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: productKeys.list(filters as Record<string, unknown>),
    queryFn: async ({ signal }) => {
      const query = toQueryString(filters);
      // El backend pagina con LengthAwarePaginator, asi que retorna
      // { data: [...], meta: {...}, links: {...} }. Usamos getPaginated
      // para preservar el shape completo antes de validar con Zod.
      // Pasamos `signal` para que TanStack Query pueda cancelar el fetch
      // cuando el usuario sigue buscando y queremos descartar la respuesta
      // obsoleta (QW5).
      const response = await getPaginated<unknown>(`/products${query ? `?${query}` : ''}`, { signal });
      return PaginatedProductsSchema.parse(response);
    },
    placeholderData: (prev) => prev,
    staleTime: 0,
    refetchOnMount: 'always',
    enabled: options.enabled ?? true,
  });
}

export function useProduct(productId: number) {
  return useQuery({
    queryKey: productKeys.detail(productId),
    queryFn: async () => {
      const data = await getOne<unknown>(`/products/${productId}`);
      return ProductSchema.parse(data);
    },
    enabled: Number.isFinite(productId) && productId > 0,
    staleTime: 0,
    refetchOnMount: 'always',
  });
}

export function useCreateProduct() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, unknown>('/products', input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
  });
}

export function useUpdateProduct() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...input }: { id: number; [k: string]: unknown }) =>
      patchOne<Record<string, unknown>, unknown>(`/products/${id}`, input),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
      void qc.invalidateQueries({ queryKey: productKeys.detail(id) });
    },
  });
}

export function useDeleteProduct() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/products/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
  });
}

export function useProductStockStatus(productId: number) {
  return useQuery({
    queryKey: productKeys.stockStatus(productId),
    queryFn: async () => {
      const data = await getOne<unknown>(`/inventory-center/products/${productId}/stock-status`);
      return ProductStockStatusSchema.parse(data);
    },
    enabled: Number.isFinite(productId) && productId > 0,
  });
}

export function useReorderSuggestions(warehouseId?: number) {
  return useQuery({
    queryKey: [...productKeys.reorder(), { warehouseId: warehouseId ?? null }],
    queryFn: async () => {
      const query = warehouseId ? `?warehouse_id=${warehouseId}` : '';
      const data = await getOne<unknown>(`/inventory-center/reorder-suggestions${query}`);
      return ReorderSuggestionsResponseSchema.parse(data);
    },
  });
}

export function useAlertsSummary() {
  return useQuery({
    queryKey: productKeys.alertsSummary(),
    queryFn: async () => {
      const data = await getOne<unknown>('/inventory-center/alerts-summary');
      return AlertsSummarySchema.parse(data);
    },
    refetchInterval: 60_000,
  });
}

export function useBulkAction() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: {
      product_ids: number[];
      action: string;
      payload?: Record<string, unknown>;
    }) => postOne<typeof input, { data: { affected: number } }>(
      '/inventory-center/products/bulk-action',
      input,
    ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
  });
}

// =====================================================================
// Catalogos auxiliares (lookups para forms + pagina /catalogs)
// =====================================================================

export function useBrands() {
  return useQuery({
    queryKey: catalogKeys.brands(),
    queryFn: async () => {
      const data = await getMany<unknown>('/brands');
      return z.array(BrandSchema).parse(data);
    },
  });
}

export function useCategoriesTree() {
  return useQuery({
    queryKey: catalogKeys.categoryTree(),
    queryFn: async () => {
      const data = await getOne<unknown>('/categories/tree');
      return z.array(CategorySchema).parse(data);
    },
  });
}

export function useCategories() {
  return useQuery({
    queryKey: catalogKeys.categories(),
    queryFn: async () => {
      const data = await getMany<unknown>('/categories');
      return z.array(CategorySchema).parse(data);
    },
  });
}

export function useTags() {
  return useQuery({
    queryKey: catalogKeys.tags(),
    queryFn: async () => {
      const data = await getMany<unknown>('/tags');
      return TagSchema.array().parse(data);
    },
  });
}

export function useWarrantyPolicies() {
  return useQuery({
    queryKey: catalogKeys.warrantyPolicies(),
    queryFn: async () => {
      const data = await getMany<unknown>('/warranty-policies');
      return z.array(WarrantyPolicySchema).parse(data);
    },
  });
}

export function useExchangeRateTypes() {
  return useQuery({
    queryKey: catalogKeys.exchangeRateTypes(),
    queryFn: async () => {
      const data = await getMany<unknown>('/currency/rate-types');
      return z.array(ExchangeRateTypeSchema).parse(data);
    },
  });
}

export function usePriceLists(activeOnly = true) {
  return useQuery({
    queryKey: [...catalogKeys.priceLists(), { activeOnly }],
    queryFn: async () => {
      const query = activeOnly ? '?active_only=1' : '';
      const data = await getMany<unknown>(`/price-lists${query}`);
      return z.array(PriceListSchema).parse(data);
    },
  });
}

export function useWarehouses() {
  return useQuery({
    queryKey: catalogKeys.warehouses(),
    queryFn: async () => {
      const data = await getMany<unknown>('/warehouses');
      return z.array(WarehouseSchema).parse(data);
    },
  });
}

export function useBranches() {
  return useQuery({
    queryKey: catalogKeys.branches(),
    queryFn: async () => {
      const data = await getMany<unknown>('/branches');
      return z.array(BranchSchema).parse(data);
    },
  });
}

// =====================================================================
// Exchange rates (rates historicas: BCV hoy, Paralelo ayer, etc.)
// =====================================================================

export function useExchangeRates(filters?: { rate_type_id?: number; from?: string; to?: string }) {
  return useQuery({
    queryKey: [...catalogKeys.exchangeRates(), filters ?? {}] as const,
    queryFn: async () => {
      const params = new URLSearchParams();
      if (filters?.rate_type_id) params.set('rate_type_id', String(filters.rate_type_id));
      if (filters?.from) params.set('from', filters.from);
      if (filters?.to) params.set('to', filters.to);
      const query = params.toString();
      const data = await getMany<unknown>(`/currency/rates${query ? `?${query}` : ''}`);
      // Respuesta esperada: array de rates con campo `type` (relacion cargada).
      return data;
    },
  });
}

export function useExchangeRate(rateId: number) {
  return useQuery({
    queryKey: catalogKeys.exchangeRate(rateId),
    queryFn: async () => {
      const data = await getOne<unknown>(`/currency/rates/${rateId}`);
      return data;
    },
    enabled: Number.isFinite(rateId) && rateId > 0,
  });
}

export function useCreateExchangeRate() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, unknown>('/currency/rates', input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.exchangeRates() });
    },
  });
}

export function useActivateExchangeRate() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (rateId: number) =>
      patchOne<Record<string, never>, unknown>(`/currency/rates/${rateId}/activate`, {}),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.exchangeRates() });
    },
  });
}

export function useDeactivateExchangeRate() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (rateId: number) =>
      patchOne<Record<string, never>, unknown>(`/currency/rates/${rateId}/deactivate`, {}),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.exchangeRates() });
    },
  });
}

// CRUDs de tipos de tasa (para la pagina /inventory/currency)
export function useCreateExchangeRateType() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, unknown>('/currency/rate-types', input),
    onSuccess: (_data, variables) => {
      void qc.invalidateQueries({ queryKey: catalogKeys.exchangeRateTypes() });
      const id = (variables as { id?: number }).id;
      if (id) void qc.invalidateQueries({ queryKey: catalogKeys.exchangeRateType(id) });
    },
  });
}

export function useUpdateExchangeRateType() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...input }: { id: number; [k: string]: unknown }) =>
      patchOne<Record<string, unknown>, unknown>(`/currency/rate-types/${id}`, input),
    onSuccess: (_data, variables) => {
      void qc.invalidateQueries({ queryKey: catalogKeys.exchangeRateTypes() });
      void qc.invalidateQueries({ queryKey: catalogKeys.exchangeRateType(variables.id) });
    },
  });
}

export function useDeleteExchangeRateType() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/currency/rate-types/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.exchangeRateTypes() });
    },
  });
}

// CRUDs de catalogos (para la pagina /inventory/catalogs)
export function useCreateBrand() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, unknown>('/brands', input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.brands() });
    },
  });
}

export function useUpdateBrand() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...input }: { id: number; [k: string]: unknown }) =>
      patchOne<Record<string, unknown>, unknown>(`/brands/${id}`, input),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: catalogKeys.brands() });
      void qc.invalidateQueries({ queryKey: catalogKeys.brand(id) });
    },
  });
}

export function useDeleteBrand() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/brands/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.brands() });
    },
  });
}

export function useCreateCategory() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, unknown>('/categories', input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.categories() });
      void qc.invalidateQueries({ queryKey: catalogKeys.categoryTree() });
    },
  });
}

export function useUpdateCategory() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...input }: { id: number; [k: string]: unknown }) =>
      patchOne<Record<string, unknown>, unknown>(`/categories/${id}`, input),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: catalogKeys.categories() });
      void qc.invalidateQueries({ queryKey: catalogKeys.categoryTree() });
      void qc.invalidateQueries({ queryKey: catalogKeys.category(id) });
    },
  });
}

export function useDeleteCategory() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/categories/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.categories() });
      void qc.invalidateQueries({ queryKey: catalogKeys.categoryTree() });
    },
  });
}

export function useCreateTag() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, unknown>('/tags', input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.tags() });
    },
  });
}

export function useUpdateTag() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...input }: { id: number; [k: string]: unknown }) =>
      patchOne<Record<string, unknown>, unknown>(`/tags/${id}`, input),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: catalogKeys.tags() });
      void qc.invalidateQueries({ queryKey: catalogKeys.tag(id) });
    },
  });
}

export function useDeleteTag() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/tags/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.tags() });
    },
  });
}

// =====================================================================
// Mutations de catalogos administrativos (Branches, Warehouses,
// Warranty Policies, Price Lists). Usadas por los managers en
// /inventory/admin y por los inline-create en ProductForm / PricesEditor.
// =====================================================================

// --- Branches ---
export function useCreateBranch() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, unknown>('/branches', input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.branches() });
    },
  });
}

export function useUpdateBranch() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...input }: { id: number; [k: string]: unknown }) =>
      patchOne<Record<string, unknown>, unknown>(`/branches/${id}`, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.branches() });
    },
  });
}

export function useDeleteBranch() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/branches/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.branches() });
    },
  });
}

// --- Warehouses ---
export function useCreateWarehouse() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, unknown>('/warehouses', input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.warehouses() });
    },
  });
}

export function useUpdateWarehouse() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...input }: { id: number; [k: string]: unknown }) =>
      patchOne<Record<string, unknown>, unknown>(`/warehouses/${id}`, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.warehouses() });
    },
  });
}

export function useDeleteWarehouse() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/warehouses/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.warehouses() });
    },
  });
}

// --- Warranty Policies ---
export function useCreateWarrantyPolicy() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, unknown>('/warranty-policies', input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.warrantyPolicies() });
    },
  });
}

export function useUpdateWarrantyPolicy() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...input }: { id: number; [k: string]: unknown }) =>
      patchOne<Record<string, unknown>, unknown>(`/warranty-policies/${id}`, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.warrantyPolicies() });
    },
  });
}

export function useDeleteWarrantyPolicy() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/warranty-policies/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.warrantyPolicies() });
    },
  });
}

// --- Price Lists ---
export function useCreatePriceList() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: Record<string, unknown>) =>
      postOne<Record<string, unknown>, unknown>('/price-lists', input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.priceLists() });
    },
  });
}

export function useUpdatePriceList() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...input }: { id: number; [k: string]: unknown }) =>
      patchOne<Record<string, unknown>, unknown>(`/price-lists/${id}`, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.priceLists() });
    },
  });
}

export function useDeletePriceList() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/price-lists/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: catalogKeys.priceLists() });
    },
  });
}

// =====================================================================
// Sync de relaciones (categorias/tags) por producto
// =====================================================================

export function useSyncProductCategories() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, category_ids }: { id: number; category_ids: number[] }) =>
      patchOne<{ category_ids: number[] }, { data: number[] }>(
        `/products/${id}/categories`,
        { category_ids },
      ),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: productKeys.detail(id) });
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
  });
}

export function useSyncProductTags() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, tag_ids }: { id: number; tag_ids: number[] }) =>
      patchOne<{ tag_ids: number[] }, { data: number[] }>(
        `/products/${id}/tags`,
        { tag_ids },
      ),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: productKeys.detail(id) });
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
  });
}
// Recalcular precio y actualizar margen (Fase profit).
export function useRecalculateProductPrice() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, profit_margin }: { id: number; profit_margin?: number | null }) =>
      postOne<{ profit_margin?: number | null }, { product_id: number; base_price: number; profit_margin: number; last_purchase_cost: number }>(
        `/inventory-center/products/${id}/recalculate-price`,
        profit_margin != null ? { profit_margin } : {},
      ),
    onSuccess: (_: unknown, vars: { id: number; profit_margin?: number | null }) => {
      void qc.invalidateQueries({ queryKey: productKeys.detail(vars.id) });
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
  });
}

export function useUpdateProductProfitMargin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, profit_margin }: { id: number; profit_margin: number }) =>
      patchOne<{ profit_margin: number }, { product_id: number; profit_margin: number; base_price: number | null }>(
        `/inventory-center/products/${id}/profit-margin`,
        { profit_margin },
      ),
    onSuccess: (response, vars) => {
      const data = response;
      if (data == null) return;
      qc.setQueryData<unknown>(productKeys.detail(vars.id), (prev: unknown) => {
        if (prev == null || typeof prev !== 'object') return prev;
        const next: Record<string, unknown> = { ...(prev as Record<string, unknown>) };
        if ('profit_margin' in data) next.profit_margin = data.profit_margin;
        if ('base_price' in data) next.base_price = data.base_price;
        return next;
      });
      void qc.invalidateQueries({ queryKey: productKeys.detail(vars.id) });
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
  });
}

// =====================================================================
// Fase 1 - IMEI scanner: lista ProductUnits disponibles de un almacen
// para el modulo de traslados. Usado por el dialog CreateTransfer cuando
// el user quiere transferir serializados.
// =====================================================================

export const ProductUnitLookupSchema = z.object({
  id: z.number().int().positive(),
  product_id: z.number().int().positive(),
  warehouse_id: z.number().int().positive(),
  serial_type: z.string(),
  serial_number: z.string(),
  status: z.string(),
});
export type ProductUnitLookup = z.infer<typeof ProductUnitLookupSchema>;

export function useAvailableProductUnits(
  productId: number,
  warehouseId: number | null,
  search: string = '',
  status: string = 'available',
) {
  return useQuery({
    queryKey: ['available-product-units', productId, warehouseId, search, status],
    queryFn: async (): Promise<ProductUnitLookup[]> => {
      if (!productId || !warehouseId) return [];
      const params = new URLSearchParams();
      params.set('warehouse_id', String(warehouseId));
      params.set('status', status);
      if (search.trim()) params.set('search', search.trim());
      // getOne ya extrae response.data.data (envoltorio API), por lo que
      // retorna directamente el array de unidades. No usar doble unwrap.
      const response = await getOne<unknown[]>(
        `/inventory-centers/products/${productId}/units?${params.toString()}`,
      );
      const items = Array.isArray(response) ? response : [];
      return z.array(ProductUnitLookupSchema).parse(items);
    },
    enabled: Boolean(productId && warehouseId),
    staleTime: 30_000,
  });
}

// =====================================================================
// Producto: galería multi-imagen (Sprint de imágenes Nivel 2)
// =====================================================================

import { ProductImageSchema, type ProductImage } from './schemas';

const productImageKeys = {
  list: (productId: number) => ['product-images', productId] as const,
  all: () => ['product-images'] as const,
};

/**
 * Lista la galería completa de un producto (ordenadas por `sort`).
 * Cache de 60s. Invalida en cualquier mutación de imagen.
 */
export function useProductImages(productId: number | null) {
  return useQuery({
    queryKey: productImageKeys.list(productId ?? 0),
    queryFn: async (): Promise<ProductImage[]> => {
      if (!productId) return [];
      // Endpoint retorna { data: ProductImage[] }
      const response = await getMany<ProductImage>(
        `/products/${productId}/images`,
      );
      return z.array(ProductImageSchema).parse(response);
    },
    enabled: Number.isFinite(productId) && Number(productId) > 0,
    staleTime: 60_000,
  });
}

/**
 * Sube una imagen via multipart/form-data. Invalida la galería del producto
 * y todas las imagenes (el backend emite sync event).
 */
export function useUploadProductImage(productId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { file: File; alt?: string }) => {
      const formData = new FormData();
      formData.append('image', payload.file);
      if (payload.alt) formData.append('alt', payload.alt);
      const response = await api.post<{ data: ProductImage }>(
        `/products/${productId}/images`,
        formData,
        // axios detecta FormData y multipart automaticamente; sin Content-Type
        // explicito para que el boundary se setee solo.
      );
      return ProductImageSchema.parse(response.data.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: productImageKeys.list(productId) });
      void qc.invalidateQueries({ queryKey: productImageKeys.all() });
      void qc.invalidateQueries({ queryKey: productKeys.detail(productId) });
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
  });
}

/**
 * Actualiza alt/sort/is_primary de una imagen. `is_primary=true` swaps
 * atomicamente en el backend.
 */
export function useUpdateProductImage(productId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: {
      id: number;
      alt?: string;
      sort?: number;
      is_primary?: boolean;
    }) => {
      const body: Record<string, unknown> = {};
      if (payload.alt !== undefined) body.alt = payload.alt;
      if (payload.sort !== undefined) body.sort = payload.sort;
      if (payload.is_primary !== undefined) body.is_primary = payload.is_primary;
      const response = await api.patch<{ data: ProductImage }>(
        `/products/${productId}/images/${payload.id}`,
        body,
      );
      return ProductImageSchema.parse(response.data.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: productImageKeys.list(productId) });
      void qc.invalidateQueries({ queryKey: productKeys.detail(productId) });
    },
  });
}

/**
 * Soft delete. La fila queda con deleted_at; un job de limpieza la borra
 * fisicamente despues de 30 dias (Nivel 4).
 */
export function useDeleteProductImage(productId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (imageId: number) => {
      await deleteOne(`/products/${productId}/images/${imageId}`);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: productImageKeys.list(productId) });
      void qc.invalidateQueries({ queryKey: productImageKeys.all() });
    },
  });
}

/**
 * Reordena la galería (drag-drop). Backend persiste el nuevo `sort` por
 * cada imagen en una sola request.
 */
export function useReorderProductImages(productId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (orderedIds: number[]) => {
      await api.patch(
        `/products/${productId}/images/reorder`,
        { ordered_ids: orderedIds },
      );
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: productImageKeys.list(productId) });
    },
  });
}
