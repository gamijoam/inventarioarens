/**
 * Guard de rutas autenticadas.
 *
 * Modelo Plan C (cookie httpOnly + session store hidratado):
 * - El usuario ya esta hidratado en localStorage (user/tenant/permissions).
 * - Si NO esta hidratado -> redirigir a /login (sin spinner, sync).
 * - Si esta hidratado pero expiresAt ya paso -> limpiar store y redirigir.
 * - Si esta hidratado y vigente -> renderizar children.
 *
 * Las rutas protegidas usan tambien `beforeLoad` en su definicion para
 * un check sincronico via document.cookie (ver routes/_authed.tsx y
 * routes/login.tsx). Eso elimina el bug de "Cargando sesion..." porque
 * el redirect ocurre ANTES de cualquier render.
 *
 * Ver docs/AUTH_COOKIE_API.md seccion "Routing (sync detection de sesion)".
 */
import { type ReactNode, useEffect } from 'react';
import { useRouter } from '@tanstack/react-router';

import { Spinner } from '@/components/ui/Spinner';
import { hasAuthCookie, useSessionStore } from '@/stores/session';

interface RequireAuthProps {
  children: ReactNode;
}

/**
 * Componente: protege un segmento autenticado.
 *
 * Renderiza un spinner brevisimo mientras se decide si hay sesion, pero
 * normalmente el beforeLoad de la ruta ya valido que hay cookie + store,
 * asi que el spinner no se ve.
 */
export function RequireAuth({ children }: RequireAuthProps) {
  const router = useRouter();
  const user = useSessionStore((s) => s.user);
  const tenant = useSessionStore((s) => s.tenant);
  const expiresAt = useSessionStore((s) => s.expiresAt);
  const permissions = useSessionStore((s) => s.permissions);

  // Si no hay cookie httpOnly -> mandar a /login (sync, sin request).
  // Esto cubre el caso edge donde el user manipularia el localStorage
  // para inyectar datos sin cookie real.
  useEffect(() => {
    if (!hasAuthCookie() && !user) {
      void router.navigate({ to: '/login' });
    } else if (expiresAt && new Date(expiresAt).getTime() < Date.now()) {
      // Sesion expirada segun el store. Limpiar y mandar a /login.
      useSessionStore.getState().clearSession();
      void router.navigate({ to: '/login' });
    }
  }, [router, user, expiresAt]);

  const isReady = Boolean(user && tenant && permissions.size > 0);

  if (!isReady) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <Spinner size="lg" label="Cargando sesión..." />
      </div>
    );
  }

  return <>{children}</>;
}