import { createFileRoute, Outlet } from '@tanstack/react-router';
import { useEffect, useMemo } from 'react';

import { AuthedLayout } from '@/components/layout/AuthedLayout';
import { RequireAuth } from '@/auth/RequireAuth';
import { useSessionStore } from '@/stores/session';
import { PermissionProvider, buildPermissionValue } from '@/permissions/PermissionContext';
import { registerUnauthorizedHandler } from '@/api/client';
import { applyDevSession, isAuthDisabled } from '@/auth/devBypass';

export const Route = createFileRoute('/_authed')({
  component: AuthedLayoutComponent,
});

function AuthedLayoutComponent() {
  const authDisabled = isAuthDisabled();

  // En modo bypass, inyectamos la sesion fake sincrónicamente antes de
  // renderizar para que PermissionProvider tenga todos los permisos desde
  // el primer render. useMemo con side-effect intencional: solo se ejecuta
  // una vez y solo cuando bypass esta activo.
  const permissionValue = useMemo(() => {
    if (authDisabled && useSessionStore.getState().permissions.size === 0) {
      applyDevSession();
    }
    const state = useSessionStore.getState();
    return buildPermissionValue(
      Array.from(state.permissions),
      state.roles,
      state.scopeStatus,
      state.scopes,
    );
  }, [authDisabled]);

  // El handler 401 ya redirige desde el cliente HTTP.
  // Registramos un no-op para mantener la API consistente.
  useEffect(() => {
    registerUnauthorizedHandler(() => {
      window.location.href = '/login';
    });
  }, []);

  if (authDisabled) {
    return (
      <PermissionProvider initial={permissionValue}>
        <AuthedLayout>
          <Outlet />
        </AuthedLayout>
      </PermissionProvider>
    );
  }

  return (
    <RequireAuth>
      <PermissionProvider initial={permissionValue}>
        <AuthedLayout>
          <Outlet />
        </AuthedLayout>
      </PermissionProvider>
    </RequireAuth>
  );
}