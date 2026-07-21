/**
 * Hooks de lookups (catalogos auxiliares) compartidos por formularios.
 * Re-export simplificado de los hooks de api.ts para import mas limpio.
 */
export {
  useBrands,
  useCategories,
  useCategoriesTree,
  useTags,
  useWarrantyPolicies,
  useExchangeRateTypes,
  usePriceLists,
  useWarehouses,
  useProductImages,
} from './api';