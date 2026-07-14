import { createFileRoute, Outlet } from '@tanstack/react-router';
import { useEffect } from 'react';

import { AuthedLayout } from '@/components/layout/AuthedLayout';
import { RequireAuth } from '@/auth/RequireAuth';
import { useSessionStore } from '@/stores/session';
import { PermissionProvider, buildPermissionValue } from '@/permissions/PermissionContext';
import { registerUnauthorizedHandler } from '@/api/client';

export const Route = createFileRoute('/_authed')({
  component: AuthedLayoutComponent,
});

function AuthedLayoutComponent() {
  const permissions = useSessionStore((s) => s.permissions);
  const roles = useSessionStore((s) => s.roles);
  const scopeStatus = useSessionStore((s) => s.scopeStatus);
  const scopes = useSessionStore((s) => s.scopes);
  const permissionValue = buildPermissionValue(
    Array.from(permissions),
    roles,
    scopeStatus,
    scopes,
  );

  // El handler 401 ya redirige desde el cliente HTTP.
  // Registramos un no-op para mantener la API consistente.
  useEffect(() => {
    registerUnauthorizedHandler(() => {
      window.location.href = '/login';
    });
  }, []);

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