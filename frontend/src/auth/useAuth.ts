/**
 * Hook de autenticacion que combina session store + endpoints.
 *
 * Modelo Plan C (cookie httpOnly):
 * - El token NO vive en el store. Vive en una cookie httpOnly que el
 *   navegador envia automaticamente (no la manipulamos).
 * - signIn: recibe la sesion del backend, la mete al store (sin token)
 *   y la queryClient clear() para forzar re-fetch con el nuevo tenant.
 * - signOut: llama al backend (que limpia la cookie) y luego limpia el store.
 * - switchTo: igual que signIn pero para cambio de tenant (backend rota cookie).
 * - refreshSession: dispara /me manualmente para refrescar permisos.
 *
 * Ver docs/AUTH_COOKIE_API.md.
 */
import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';

import {
  login as apiLogin,
  logout as apiLogout,
  me as apiMe,
  switchTenant as apiSwitchTenant,
  type LoginRequest,
} from '@/api/endpoints/auth';
import { useSessionStore } from '@/stores/session';

interface UseAuthResult {
  isAuthenticated: boolean;
  isReady: boolean;
  signIn: (tenantSlug: string, payload: LoginRequest) => Promise<void>;
  signOut: () => Promise<void>;
  switchTo: (slug: string) => Promise<void>;
  refreshSession: () => Promise<void>;
}

/**
 * Hook principal de auth. Lee el session store (que ya no incluye token)
 * y expone helpers para login/logout/switch-tenant + un refreshSession
 * que rehidrata permisos via GET /api/auth/me.
 */
export function useAuth(): UseAuthResult {
  const user = useSessionStore((s) => s.user);
  const permissions = useSessionStore((s) => s.permissions);
  const queryClient = useQueryClient();

  // Sin token: "autenticado" significa "tenemos datos de sesion hidratados".
  // La validacion REAL contra backend ocurre en cada request (401 si cookie expirada).
  const isAuthenticated = Boolean(user);
  const isReady = permissions.size > 0 || !user;

  const refreshSession = useCallback(async () => {
    try {
      const me = await apiMe();
      useSessionStore.getState().setSession({
        expiresAt: me.expires_at ?? null,
        user: me.user,
        tenant: me.tenant,
        roles: Array.isArray(me.roles)
          ? me.roles.map((r: unknown) =>
              typeof r === 'string' ? r : ((r as { name?: string }).name ?? String(r)),
            )
          : [],
        permissions: me.permissions ?? [],
        scopeStatus: me.scope_status ?? 'none',
        scopes: me.scopes ?? {
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
    } catch {
      // Si falla (401), el interceptor ya limpia la sesion y redirige a /login.
    }
  }, []);

  const signIn = useCallback(
    async (tenantSlug: string, payload: LoginRequest) => {
      // La cookie httpOnly se emite automaticamente en el response del login.
      // Solo necesitamos guardar el resto en el store.
      const data = await apiLogin(tenantSlug, payload);

      useSessionStore.getState().setTenant({
        id: data.tenant.id,
        slug: data.tenant.slug,
        name: data.tenant.name,
        is_active: data.tenant.is_active ?? true,
      });

      useSessionStore.getState().setSession({
        expiresAt: data.expires_at,
        user: data.user,
        tenant: data.tenant,
        roles: data.roles.map((r: unknown) =>
          typeof r === 'string' ? r : ((r as { name?: string }).name ?? String(r)),
        ),
        permissions: data.permissions,
        scopeStatus: data.scope_status,
        scopes: data.scopes,
      });

      // Invalidamos todas las queries para forzar re-fetch con el nuevo tenant.
      queryClient.clear();
    },
    [queryClient],
  );

  const signOut = useCallback(async () => {
    try {
      // El backend limpia la cookie httpOnly en el response.
      await apiLogout();
    } catch {
      // Ignorar errores de logout (ya limpiamos la sesion local).
    } finally {
      useSessionStore.getState().clearSession();
      queryClient.clear();
    }
  }, [queryClient]);

  const switchTo = useCallback(
    async (slug: string) => {
      const data = await apiSwitchTenant(slug);
      useSessionStore.getState().setSession({
        expiresAt: data.expires_at,
        user: data.user,
        tenant: data.tenant,
        roles: data.roles.map((r: unknown) =>
          typeof r === 'string' ? r : ((r as { name?: string }).name ?? String(r)),
        ),
        permissions: data.permissions,
        scopeStatus: data.scope_status,
        scopes: data.scopes,
      });
      queryClient.clear();
    },
    [queryClient],
  );

  return {
    isAuthenticated,
    isReady,
    signIn,
    signOut,
    switchTo,
    refreshSession,
  };
}