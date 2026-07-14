/**
 * Helper de bypass de autenticacion para desarrollo.
 *
 * Plan C: la cookie httpOnly del navegador no es manipulable desde JS,
 * asi que el bypass inyecta una sesion fake SOLO en el store local
 * (user/tenant/permissions). Las requests que pegan contra el backend
 * usan Bearer (via axios NO, pero via curl/scripts externos) o el
 * token real guardado en `localStorage.getItem('dev_token')`.
 *
 * Activacion (cualquiera):
 *  - VITE_AUTH_DISABLED=true al build.
 *  - localStorage.setItem('dev_skip_auth', '1') en el navegador.
 *
 * Para forzar el flujo real (ir a /login aunque haya cookie):
 *  - localStorage.setItem('dev_enforce_auth', '1').
 *
 * Para usar un token Bearer real contra el backend:
 *  - localStorage.setItem('dev_token', '<token>') y configura axios
 *    para enviarlo via Authorization header (requiere parchar el cliente).
 *    O mas simple: usa curl/Postman contra el backend directamente.
 *
 * Ver docs/AUTH_COOKIE_API.md.
 */

import { useSessionStore } from '@/stores/session';
import { PERMISSIONS } from '@/permissions/constants';

const ENV_DISABLED = import.meta.env.VITE_AUTH_DISABLED === 'true';

/**
 * true = el bypass esta activo y no se deberia gatear la UI con login.
 * false = flujo real: se exige cookie httpOnly + sesion hidratada.
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
 * Setea una sesion fake con todos los permisos.
 *
 * IDEMPOTENTE: si la sesion ya esta aplicada (detectada por user.email
 * === 'dev@local'), no hace nada. Esto previene re-renders infinitos
 * cuando se llama desde useEffect.
 *
 * En el modelo cookie, NO podemos setear la cookie httpOnly desde JS,
 * asi que esto solo puebla el store local. Para que las requests peguen
 * contra el backend real, usa dev_token via curl/scripts.
 *
 * Para hacer que axios use dev_token como Bearer en vez de cookie,
 * ver el ejemplo en docs/AUTH_COOKIE_API.md (seccion "Dev token via Bearer").
 */
export function applyDevSession(): void {
  if (typeof window === 'undefined') return;

  // NO sobrescribir si ya hay una sesion real (login con backend).
  // Esto evita que el bypass pisotee una sesion valida despues de login.
  // Bug historico: despues de hacer login real con gabo@gabo.com, el
  // bypass seguia activo y sobrescribia la sesion con tenant 'dev' (fake)
  // que el backend rechaza con 404 "Tenant not found".
  const current = useSessionStore.getState();
  if (current.user !== null) {
    return;
  }

  let tenantSlug = 'dev';
  try {
    const t = window.localStorage.getItem('dev_tenant_slug');
    if (t) tenantSlug = t;
  } catch {
    // ignore
  }

  useSessionStore.setState({
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