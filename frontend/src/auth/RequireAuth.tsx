import { type ReactNode, useEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useRouter } from '@tanstack/react-router';

import { Spinner } from '@/components/ui/Spinner';
import { useSessionStore } from '@/stores/session';
import { me as apiMe } from '@/api/endpoints/auth';

// Activar debug logs con: localStorage.setItem('auth_debug', '1') y refrescar.
const DEBUG = typeof window !== 'undefined' && localStorage.getItem('auth_debug') === '1';
const log = (...args: unknown[]) => {
  if (DEBUG) console.warn('[AUTH]', ...args);
};

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
 * - El fetch SOLO corre cuando needsMe es true (token presente + sin
 *    permissions). Tras login, el signIn setea permissions directamente, asi
 *    que no se duplica el fetch.
 * - El retry queda en false para que un token expirado se limpie rapido
 *    sin esperar 3 reintentos.
 */
export function RequireAuth({ children }: RequireAuthProps) {
  const router = useRouter();
  const token = useSessionStore((s) => s.token);
  const permissions = useSessionStore((s) => s.permissions);

  const needsMe = Boolean(token) && permissions.size === 0;
  log('render', {
    token: token ? '<len=' + token.length + '>' : null,
    permissionsSize: permissions.size,
    needsMe,
  });

  const { isLoading, isError, error } = useQuery({
    queryKey: ['auth', 'me'] as const,
    queryFn: async () => {
      log('queryFn: start');
      const currentToken = useSessionStore.getState().token;
      if (!currentToken) {
        log('queryFn: no token, abort');
        throw new Error('No token available');
      }
      const me = await apiMe();
      log('queryFn: me() returned', {
        keys: Object.keys(me),
        permissionsCount: me.permissions?.length,
        rolesType: typeof me.roles?.[0],
        rolesIsArray: Array.isArray(me.roles),
      });
      useSessionStore.getState().setSession({
        token: currentToken,
        expiresAt: me.expires_at ?? null,
        user: me.user,
        tenant: me.tenant,
        // El backend retorna roles como strings (slugs) o como objetos Role
        // dependiendo del endpoint. Normalizamos a string[] siempre.
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
      log('queryFn: setSession called');
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
    log('error effect: clearing session + navigating to /login', {
      message: error instanceof Error ? error.message : String(error),
    });
    useSessionStore.getState().clearSession();
    void router.navigate({ to: '/login' });
  }, [isError, error, router]);

  // Si NO hay token (caso: usuario borro localStorage y refresco), mandamos
  // al login. Sin este effect, el componente mostraba "Redirigiendo al
  // login..." indefinidamente porque nunca disparaba la navegacion.
  useEffect(() => {
    if (!token && handledErrorRef.current === false) {
      log('no-token effect: navigating to /login');
      void router.navigate({ to: '/login' });
    }
  }, [token, router]);

  const isReady = permissions.size > 0 || !token;
  log('render: isReady=', isReady, 'isLoading=', isLoading, 'isError=', isError);

  if (!isReady || (needsMe && (isLoading || (isError && !handledErrorRef.current)))) {
    log('render: showing spinner (Cargando sesion...)');
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <Spinner size="lg" label="Cargando sesión..." />
      </div>
    );
  }

  if (!token) {
    log('render: no token, showing redirect spinner');
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <Spinner size="lg" label="Redirigiendo al login..." />
      </div>
    );
  }

  log('render: showing children');
  return <>{children}</>;
}