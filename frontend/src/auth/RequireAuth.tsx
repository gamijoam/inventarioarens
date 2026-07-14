import { type ReactNode, useEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useRouter } from '@tanstack/react-router';

import { Spinner } from '@/components/ui/Spinner';
import { useSessionStore } from '@/stores/session';
import { me as apiMe } from '@/api/endpoints/auth';

interface RequireAuthProps {
  children: ReactNode;
}

/**
 * Guard: protege las rutas autenticadas.
 *
 * Comportamiento:
 * 1. Si NO hay token persistido -> redirige a /login.
 * 2. Si hay token pero permissions.size === 0 (store rehidratado sin permisos,
 *    caso normal tras un refresh de pagina) -> dispara GET /api/auth/me con
 *    useQuery para rehidratar permissions/roles/scopes, mostrando un spinner.
 * 3. Si el /me falla (401 token expirado, o error de red) -> limpia la sesion
 *    y redirige a /login.
 * 4. Si hay token Y permissions.size > 0 -> renderiza los children.
 *
 * Notas de diseno:
 * - Usamos useQuery de TanStack Query (NO de react-router) para que el fetch
 *    compita con dedupe, retry y cache compartido del QueryClient.
 * - El fetch SOLO corre cuando `needsMe` es true (token presente + sin
 *    permissions). Tras login, el signIn setea permissions directamente, asi
 *    que no se duplica el fetch.
 * - El retry queda en `false` para que un token expirado se limpie rapido
 *    sin esperar 3 reintentos.
 */
export function RequireAuth({ children }: RequireAuthProps) {
  const router = useRouter();
  const token = useSessionStore((s) => s.token);
  const permissions = useSessionStore((s) => s.permissions);

  const needsMe = Boolean(token) && permissions.size === 0;

  const { isLoading, isError } = useQuery({
    queryKey: ['auth', 'me'] as const,
    queryFn: async () => {
      const currentToken = useSessionStore.getState().token;
      if (!currentToken) {
        throw new Error('No token available');
      }
      const me = await apiMe();
      useSessionStore.getState().setSession({
        token: currentToken,
        expiresAt: me.expires_at,
        user: me.user,
        tenant: me.tenant,
        roles: me.roles.map((r) => r.name),
        permissions: me.permissions,
        scopeStatus: me.scope_status,
        scopes: me.scopes,
      });
      return me;
    },
    enabled: needsMe,
    retry: false,
    staleTime: 60_000,
  });

  // Si el fetch falla, limpiamos sesion y mandamos al login.
  // Usamos un ref para garantizar que el side-effect corra UNA sola vez
  // por cada transicion false->true de isError; asi evitamos loops de
  // re-render (clearSession cambia el store, lo cual re-renderiza, y si
  // isError sigue true el useEffect volveria a disparar).
  const handledErrorRef = useRef(false);
  useEffect(() => {
    if (!isError || handledErrorRef.current) return;
    handledErrorRef.current = true;
    useSessionStore.getState().clearSession();
    void router.navigate({ to: '/login' });
  }, [isError, router]);

  const isReady = permissions.size > 0 || !token;

  if (!isReady || (needsMe && (isLoading || (isError && !handledErrorRef.current)))) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <Spinner size="lg" label="Cargando sesión..." />
      </div>
    );
  }

  if (!token) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <Spinner size="lg" label="Redirigiendo al login..." />
      </div>
    );
  }

  return <>{children}</>;
}