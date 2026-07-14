/**
 * Hook de autenticacion que combina session store + endpoints.
 * Centraliza los flujos de login, logout, switchTenant y refresh de me().
 */
import { useCallback, useEffect } from 'react';

import { login as apiLogin, logout as apiLogout, me as apiMe, switchTenant as apiSwitchTenant } from '@/api/endpoints/auth';
import { useSessionStore } from '@/stores/session';
import { useQueryClient } from '@tanstack/react-query';
import type { LoginRequest } from '@/api/endpoints/auth';

interface UseAuthResult {
  isAuthenticated: boolean;
  isReady: boolean;
  signIn: (payload: LoginRequest) => Promise<void>;
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
  const session = useSessionStore();
  const queryClient = useQueryClient();

  const refreshSession = useCallback(async () => {
    if (!session.token) return;
    try {
      const me = await apiMe();
      if (!session.token) return;
      session.setSession({
        token: session.token,
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
  }, [session]);

  const signIn = useCallback(
    async (payload: LoginRequest) => {
      const data = await apiLogin(payload);
      session.setSession({
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
    [session, queryClient],
  );

  const signOut = useCallback(async () => {
    try {
      await apiLogout();
    } catch {
      // Ignorar errores de logout (ya limpiamos la sesion local).
    } finally {
      session.clearSession();
      queryClient.clear();
    }
  }, [session, queryClient]);

  const switchTo = useCallback(
    async (slug: string) => {
      const data = await apiSwitchTenant(slug);
      session.setSession({
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
    [session, queryClient],
  );

  // Al montar, si hay token persistido pero no hay permissions cargadas, refrescar.
  const isReady = session.permissions.size > 0 || !session.token;
  useEffect(() => {
    if (session.token && session.permissions.size === 0) {
      void refreshSession();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return {
    isAuthenticated: session.isAuthenticated(),
    isReady,
    signIn,
    signOut,
    switchTo,
    refreshSession,
  };
}