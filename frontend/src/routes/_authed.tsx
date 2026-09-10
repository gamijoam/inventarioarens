import { createFileRoute, Outlet, redirect } from '@tanstack/react-router';
import { useEffect, useMemo, useRef } from 'react';

import { AuthedLayout } from '@/components/layout/AuthedLayout';
import { APP_MODE, isRouteAllowedForAppMode } from '@/config/branding';
import { useSessionStore } from '@/stores/session';
import { useAuth } from '@/auth/useAuth';
import { PermissionProvider, buildPermissionValue } from '@/permissions/PermissionContext';
import { applyDevSession, isAuthDisabled, isSyntheticDevSession } from '@/auth/devBypass';

/**
 * Layout autenticado.
 *
 * El guard real (sync) ocurre en `beforeLoad` antes de cualquier render:
 *  - Si isAuthDisabled() (dev) -> dejamos pasar sin checks.
 *  - Si no hay sesion hidratada -> redirect a /login (o a /master si veniamos de ahi).
 *  - Si el usuario es super-admin y esta en una ruta no-master -> redirect a /master.
 *
 * Este patron evita "flicker" de carga y previene que los hijos se monten
 * e intenten disparar queries sin token.
 */
export const Route = createFileRoute('/_authed')({
  beforeLoad: ({ location }) => {
    if (!isRouteAllowedForAppMode(APP_MODE, location.pathname)) {
      // eslint-disable-next-line @typescript-eslint/only-throw-error
      throw redirect({ to: '/pos' });
    }

    if (isAuthDisabled()) {
      // No hacemos nada: dejamos pasar.
      return;
    }

    const { user, tenant } = useSessionStore.getState();
    // httpOnly no es visible desde document.cookie. La API valida el token
    // en cada request; aqui usamos la sesion hidratada para evitar un loop
    // de redirect inmediatamente despues del login.
    if (!user || !tenant || isSyntheticDevSession(user)) {
      // eslint-disable-next-line @typescript-eslint/only-throw-error
      throw redirect({ to: '/login' });
    }

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

  const { refreshSession } = useAuth();
  const refreshedTenantRef = useRef<number | null>(null);

  // En modo bypass, inyectamos la sesion fake UNA vez via useEffect.
  // En modo normal, refrescamos la sesion de fondo para sincronizar capacidades
  // actualizadas (evita que el localStorage retenga capacidades apagadas en la nube).
  useEffect(() => {
    if (isAuthDisabled()) {
      applyDevSession();
    } else if (user && tenant && refreshedTenantRef.current !== tenant.id) {
      refreshedTenantRef.current = tenant.id;
      void refreshSession();
    }
  }, [refreshSession, user, tenant]);

  const isHydrated = Boolean(user && tenant && permissions.size > 0);

  // permissionValue debe ser estable para no remontar PermissionProvider.
  // Lo computamos desde el state actual (que ya se actualiza via setSession
  // cuando llega la respuesta de login o el effect de bypass).
  const permissionValue = useMemo(
    () => buildPermissionValue(Array.from(permissions), roles, scopeStatus, scopes),
    [permissions, roles, scopeStatus, scopes],
  );

  // Doble verificacion: edge case de cookie set pero localStorage vacio
  // tras clear manual -> el beforeLoad ya redirige en el caso comun.
  // Esto es solo un fallback visual para el breve instante mientras
  // el useEffect del bypass aplica la sesion fake.
  if (!isHydrated && isAuthDisabled()) {
    return (
      <div className="bg-bg flex min-h-screen items-center justify-center">
        <div className="text-text-muted text-sm">Inicializando sesion...</div>
      </div>
    );
  }

  if (!user || !tenant) {
    return (
      <div className="bg-bg flex min-h-screen items-center justify-center">
        <div className="text-text-muted text-sm">Cargando sesion...</div>
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
