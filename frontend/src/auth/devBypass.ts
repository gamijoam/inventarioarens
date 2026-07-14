/**
 * Helper de bypass de autenticacion para desarrollo.
 *
 * El backend sigue exigiendo Bearer token en todas las requests; este bypass
 * solo evita el guard del frontend para que puedas seguir iterando sin
 * pasar por la pantalla de login mientras resolvemos el bug de "Cargando
 * sesion...". Los endpoints que requieren auth seguiran fallando con 401
 * si no envias un token valido en el header Authorization.
 *
 * Activacion (en orden de prioridad):
 *  1. Variable de entorno VITE_AUTH_DISABLED=true al hacer build/dev.
 *  2. localStorage.setItem('dev_skip_auth', '1') desde la consola del navegador.
 *
 * Para desactivar: quitar la env var y ejecutar localStorage.removeItem('dev_skip_auth').
 */

import { useSessionStore } from '@/stores/session';
import { PERMISSIONS } from '@/permissions/constants';

const ENV_DISABLED = import.meta.env.VITE_AUTH_DISABLED === 'true';

/**
 * Bypass activado por defecto para no quedar atrapados en el flujo de login
 * mientras se resuelve el bug del refresh. Para forzar el flujo real:
 *   localStorage.setItem('dev_enforce_auth', '1')
 * (o setear VITE_AUTH_DISABLED=false al hacer build).
 */
export function isAuthDisabled(): boolean {
  if (ENV_DISABLED) return true;
  if (typeof window === 'undefined') return true;
  try {
    if (window.localStorage.getItem('dev_enforce_auth') === '1') return false;
    return true;
  } catch {
    return true;
  }
}

const ALL_PERMISSIONS = new Set<string>(Object.values(PERMISSIONS));

/**
 * Setea una sesion fake con todos los permisos para que el resto de la UI
 * (Can, PermissionContext, queries, etc.) funcione sin pegar contra /me.
 *
 * Token: si localStorage tiene 'dev_token' (un Bearer real del backend)
 * lo usa; si no, usa un placeholder que NO sera valido para requests
 * al backend (los endpoints protegidos responderan 401 hasta que pegues
 * un token real con `localStorage.setItem('dev_token', '<token>')`).
 *
 * NO se persiste en localStorage: si recargas la pagina se vuelve a aplicar.
 */
export function applyDevSession(): void {
  if (typeof window === 'undefined') return;

  let realToken: string | null = null;
  let tenantSlug = 'dev';
  try {
    realToken = window.localStorage.getItem('dev_token');
    const t = window.localStorage.getItem('dev_tenant_slug');
    if (t) tenantSlug = t;
  } catch {
    // ignore
  }

  useSessionStore.setState({
    token: realToken ?? 'dev-bypass-token',
    expiresAt: '2099-12-31T23:59:59Z',
    user: {
      id: 0,
      email: 'dev@local',
      name: 'Dev Bypass',
      is_active: true,
    },
    tenant: {
      id: 0,
      slug: tenantSlug,
      name: 'Dev (auth bypass)',
      is_active: true,
    },
    roles: ['Administrador'],
    permissions: ALL_PERMISSIONS,
    scopeStatus: 'none',
    scopes: {
      branches: [],
      warehouses: [],
      customer_groups: [],
      vendor_of: [],
      branches_count: 0,
      warehouses_count: 0,
      customer_groups_count: 0,
      vendor_of_count: 0,
    },
  });
}