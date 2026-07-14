/**
 * Hooks de verificacion de permisos del usuario actual.
 * Leen del PermissionContext (NO calculan capabilities en cliente).
 *
 * Ver docs/FRONTEND_PERMISSIONS.md §4.
 */
import { usePermissionContext } from './PermissionContext';

/** Hook basico: devuelve true si el user tiene el permiso. */
export function useCan(permission: string): boolean {
  const { permissions } = usePermissionContext();
  return permissions.has(permission);
}

/** Hook que devuelve true si el user tiene AL MENOS UNO de los permisos. */
export function useCanAny(perms: readonly string[]): boolean {
  const { permissions } = usePermissionContext();
  return perms.some((p) => permissions.has(p));
}

/** Hook que devuelve true si el user tiene TODOS los permisos. */
export function useCanAll(perms: readonly string[]): boolean {
  const { permissions } = usePermissionContext();
  return perms.every((p) => permissions.has(p));
}