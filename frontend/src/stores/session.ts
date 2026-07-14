/**
 * Store de sesion del usuario actual.
 *
 * Persiste user + tenant + permissions + roles + scopes en localStorage.
 * NO persiste el token: el token vive en una cookie httpOnly que envia
 * el navegador automaticamente con cada request (ver docs/AUTH_COOKIE_API.md).
 *
 * Esto resuelve el bug de "Cargando sesion..." tras un refresh porque
 * la deteccion de sesion pasa a ser sincronica via document.cookie en
 * los beforeLoad de las rutas (no async via /me).
 *
 * Persistimos permissions + roles + scopes aunque pesen ~3KB porque:
 * 1. La UI puede renderizar <Can> inmediatamente sin esperar /me.
 * 2. El backend sigue siendo la fuente de verdad: signIn + signOut + el
 *    interceptor 401 refrescan este store cuando es necesario.
 *
 * Ver docs/FRONTEND_ARQUITECTURA.md §9.1 + docs/AUTH_COOKIE_API.md.
 */
import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

import type { Tenant, User, UserScopes } from '@/types/user';

export interface SessionState {
  // El token NO vive aqui. Lo maneja la cookie httpOnly del navegador.
  user: User | null;
  tenant: Tenant | null;
  roles: string[];
  permissions: Set<string>;
  scopeStatus: 'none' | 'allow' | 'restrict';
  scopes: UserScopes;
  expiresAt: string | null;

  setSession: (data: {
    expiresAt: string;
    user: User;
    tenant: Tenant;
    roles: string[];
    permissions: string[];
    scopeStatus: SessionState['scopeStatus'];
    scopes: UserScopes;
  }) => void;

  setTenant: (tenant: Tenant) => void;

  clearSession: () => void;

  hasSession: () => boolean;
}

const emptyScopes: UserScopes = {
  branches: [],
  warehouses: [],
  customer_groups: [],
  vendor_of: [],
  branches_count: 0,
  warehouses_count: 0,
  customer_groups_count: 0,
  vendor_of_count: 0,
};

const initialState = {
  user: null,
  tenant: null,
  roles: [] as string[],
  permissions: new Set<string>(),
  scopeStatus: 'none' as const,
  scopes: emptyScopes,
  expiresAt: null,
};

export const useSessionStore = create<SessionState>()(
  persist(
    (set, get) => ({
      ...initialState,

      setSession: (data) =>
        set({
          expiresAt: data.expiresAt,
          user: data.user,
          tenant: data.tenant,
          roles: data.roles,
          permissions: new Set(data.permissions),
          scopeStatus: data.scopeStatus,
          scopes: data.scopes,
        }),

      setTenant: (tenant) => set({ tenant }),

      clearSession: () => set({ ...initialState, permissions: new Set() }),

      // Sync: indica si tenemos datos de sesion hidratados.
      // NO garantiza que la cookie este vigente (eso lo verifica el backend).
      hasSession: () => Boolean(get().user && get().tenant),
    }),
    {
      name: 'inventory_session',
      storage: createJSONStorage(() => localStorage),
      // Persistimos todo MENOS el token (que vive en la cookie httpOnly).
      // Set -> Array para serializar en JSON correctamente.
      partialize: (state) => ({
        user: state.user,
        tenant: state.tenant,
        roles: state.roles,
        permissions: Array.from(state.permissions),
        scopeStatus: state.scopeStatus,
        scopes: state.scopes,
        expiresAt: state.expiresAt,
      }),
      // Cuando rehidrates, el Set vuelve como Array. Lo convertimos.
      merge: (persistedState, currentState) => {
        const persisted = persistedState as Partial<{
          permissions: string[] | Set<string>;
        }> | undefined;
        const perms = persisted?.permissions;
        return {
          ...currentState,
          ...persisted,
          permissions: new Set(Array.isArray(perms) ? perms : []),
        };
      },
    },
  ),
);

/**
 * Helpers exported para que las rutas lean document.cookie sync
 * (no async, antes de cualquier render de la app).
 *
 * Ver docs/AUTH_COOKIE_API.md seccion "Routing (sync detection de sesion)".
 */
export const AUTH_COOKIE_NAME = 'auth_token';

export function hasAuthCookie(): boolean {
  if (typeof document === 'undefined') return false;
  // Buscamos cookie_name=value (sin regex por performance).
  // document.cookie tiene formato "name1=value1; name2=value2; ..."
  return document.cookie
    .split('; ')
    .some((c) => c.startsWith(`${AUTH_COOKIE_NAME}=`));
}

export function hasAuthCookieWithValue(): string | null {
  if (typeof document === 'undefined') return null;
  const found = document.cookie
    .split('; ')
    .find((c) => c.startsWith(`${AUTH_COOKIE_NAME}=`));
  if (!found) return null;
  const eq = found.indexOf('=');
  return found.substring(eq + 1);
}