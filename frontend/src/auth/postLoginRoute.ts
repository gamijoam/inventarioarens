import { PERMISSIONS } from '@/permissions/constants';

const ADMIN_ROLES = new Set(['owner', 'administrador', 'gerente', 'almacen', 'auditor']);

/** Returns the first screen appropriate for the authenticated tenant user. */
export function getPostLoginRoute(roles: string[], permissions: string[]): '/pos' | '/pos/armar' | '/dashboard' {
  const canPrepareOrders =
    permissions.includes(PERMISSIONS.POS_VIEW) && permissions.includes(PERMISSIONS.POS_CHECKOUT);
  const normalizedRoles = roles.map((role) => role.trim().toLocaleLowerCase());
  const hasAdministrativeAccess =
    permissions.includes(PERMISSIONS.ROLES_VIEW) ||
    permissions.includes(PERMISSIONS.USERS_VIEW) ||
    permissions.includes(PERMISSIONS.SETTINGS_MANAGE) ||
    normalizedRoles.some((role) => ADMIN_ROLES.has(role));

  if (hasAdministrativeAccess || !canPrepareOrders) return '/dashboard';
  if (permissions.includes(PERMISSIONS.POS_ORDERS_HOLD)) return '/pos/armar';
  if (canPrepareOrders) return '/pos';
  return '/dashboard';
}
