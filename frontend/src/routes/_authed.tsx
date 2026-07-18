import { createFileRoute, Outlet, redirect } from '@tanstack/react-router';
import { useEffect, useMemo } from 'react';

import { AuthedLayout } from '@/components/layout/AuthedLayout';
import { useSessionStore } from '@/stores/session';
import { PermissionProvider, buildPermissionValue } from '@/permissions/PermissionContext';
import { applyDevSession, isAuthDisabled } from '@/auth/devBypass';

/**
 * Layout autenticado.
 *
 * El guard real (sync) ocurre en `beforeLoad` antes de cualquier render:
 *  - Si isAuthDisabled() (dev) -> dejamos pasar sin checks.
 *  - Si hay cookie + store hidratado -> dejamos pasar (render PermissionProvider + Outlet).
 *  - Si NO hay cookie -> redirect a /login.
 *
 * NO usamos un RequireAuth async (como antes) porque el estado de sesion
 * ya esta disponible sync desde:
 *   1. Cookie httpOnly (browser -> server, automatica).
 *   2. localStorage hidratado (zustand persist).
 *
 * Esto resuelve el bug "Cargando sesion..." tras refresh.
 *
 * Ver docs/AUTH_COOKIE_API.md seccion "Routing (sync detection de sesion)".
 */
export const Route = createFileRoute('/_authed')({
  beforeLoad: () => {
    if (isAuthDisabled()) {
      // No hacemos nada: dejamos pasar.
      return;
    }

    // Fast path: si no hay cookie httpOnly -> ni siquiera intentar renderizar.
    // hasAuthCookie() lee document.cookie sync.
    if (typeof document !== 'undefined') {
      const hasCookie = document.cookie
        .split('; ')
        .some((c) => c.startsWith('auth_token='));
      if (!hasCookie) {
        // eslint-disable-next-line @typescript-eslint/only-throw-error
        throw redirect({ to: '/login' });
      }
    }

    const { user, tenant } = useSessionStore.getState();
    if (user?.is_platform_admin && !tenant) {
      // eslint-disable-next-line @typescript-eslint/only-throw-error
      throw redirect({ to: '/master' });
    }
  },
  component: AuthedLayoutComponent,
});

function AuthedLayoutComponent() {
  const permissions = useSessionStore((s) => s.permissions);
  const roles = useSessionStore((s) => s.roles);
  const scopeStatus = useSessionStore((s) => s.scopeStatus);
  const scopes = useSessionStore((s) => s.scopes);
  const user = useSessionStore((s) => s.user);
  const tenant = useSessionStore((s) => s.tenant);

  // En modo bypass, inyectamos la sesion fake UNA vez via useEffect.
  // ANTES lo haciamos en render, lo que causaba "Cannot update a component
  // while rendering a different component" + loop infinito de re-renders.
  useEffect(() => {
    if (isAuthDisabled()) {
      applyDevSession();
    }
  }, []);

  const isHydrated = Boolean(user && tenant && permissions.size > 0);

  // permissionValue debe ser estable para no remontar PermissionProvider.
  // Lo computamos desde el state actual (que ya se actualiza via setSession
  // cuando llega la respuesta de login o el effect de bypass).
  const permissionValue = useMemo(
    () =>
      buildPermissionValue(
        Array.from(permissions),
        roles,
        scopeStatus,
        scopes,
      ),
    [permissions, roles, scopeStatus, scopes],
  );

  // Doble verificacion: edge case de cookie set pero localStorage vacio
  // tras clear manual -> el beforeLoad ya redirige en el caso comun.
  // Esto es solo un fallback visual para el breve instante mientras
  // el useEffect del bypass aplica la sesion fake.
  if (!isHydrated && isAuthDisabled()) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <div className="text-sm text-text-muted">Inicializando sesion...</div>
      </div>
    );
  }

  if (!user || !tenant) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <div className="text-sm text-text-muted">Cargando sesion...</div>
      </div>
    );
  }

  return (
    <PermissionProvider initial={permissionValue}>
      <AuthedLayout>
        <Outlet />
      </AuthedLayout>
    </PermissionProvider>
  );
}
