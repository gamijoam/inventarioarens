/**
 * Hook de autenticacion que combina session store + endpoints.
 * Centraliza los flujos de login, logout, switchTenant y refresh de me().
 *
 * Diseno:
 * - Usa selectores especificos del store para evitar re-renders innecesarios.
 * - Lee valores actuales del store con useSessionStore.getState() en handlers
 *    imperativos para evitar stale closures.
 * - El refresh automatico al montar (post-refresh de pagina) lo hace
 *    RequireAuth.tsx via useQuery. Este hook solo expone refreshSession
 *    para que Topbar pueda recargar permisos manualmente.
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
 * Hook principal de auth. Lee el session store y expone helpers
 * para login/logout/switch-tenant + un refreshSession que rehidrata
 * permissions/scopes via GET /api/auth/me.
 */
export function useAuth(): UseAuthResult {
  const token = useSessionStore((s) => s.token);
  const permissions = useSessionStore((s) => s.permissions);
  const queryClient = useQueryClient();

  const isAuthenticated = Boolean(token);
  const isReady = permissions.size > 0 || !token;

  const refreshSession = useCallback(async () => {
    const current = useSessionStore.getState();
    if (!current.token) return;
    try {
      const me = await apiMe();
      const stillActive = useSessionStore.getState();
      if (!stillActive.token) return;
      stillActive.setSession({
        token: stillActive.token,
        expiresAt: me.expires_at,
        user: me.user,
        tenant: me.tenant,
        roles: me.roles.map((r) => r.name),
        permissions: me.permissions,
        scopeStatus: me.scope_status,
        scopes: me.scopes,
      });
    } catch {
      // Si falla (401), el interceptor ya limpia la sesion.
    }
  }, []);

  const signIn = useCallback(
    async (tenantSlug: string, payload: LoginRequest) => {
      const current = useSessionStore.getState();
      // Seteamos el tenant ANTES de la request para que el interceptor
      // pueda inyectar el header X-Tenant que el backend exige.
      current.setTenant({
        id: 0,
        slug: tenantSlug,
        name: tenantSlug,
        is_active: true,
      });
      const data = await apiLogin(tenantSlug, payload);
      useSessionStore.getState().setSession({
        token: data.token,
        expiresAt: data.expires_at,
        user: data.user,
        tenant: data.tenant,
        roles: data.roles.map((r) => r.name),
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
        token: data.token,
        expiresAt: data.expires_at,
        user: data.user,
        tenant: data.tenant,
        roles: data.roles.map((r) => r.name),
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
