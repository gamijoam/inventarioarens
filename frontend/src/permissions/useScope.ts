/**
 * Hooks de verificacion de scopes por recurso.
 *
 * Comportamiento por defecto: default-allow. Si el user no tiene scope
 * asignado en una categoria, ve TODO (scope_status='none' o 'allow').
 *
 * Ver docs/INSTRUCCIONES_FRONTEND_SCOPES.md §6.
 */
import { usePermissionContext } from '@/permissions/PermissionContext';

export type ScopeCategory = 'branches' | 'warehouses' | 'customer_groups' | 'vendor_of';

export function useScopeStatus(): 'none' | 'allow' | 'restrict' {
  return usePermissionContext().scopeStatus;
}

export function useScopes() {
  return usePermissionContext().scopes;
}

/** Devuelve true si el user puede ver el recurso (no esta restringido por scope). */
export function useHasScope(category: ScopeCategory, resourceId: number): boolean {
  const { scopeStatus, scopes } = usePermissionContext();
  if (scopeStatus !== 'restrict') return true; // default-allow
  return scopes[category].includes(resourceId);
}