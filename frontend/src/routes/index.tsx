import { createFileRoute, redirect } from '@tanstack/react-router';

import { isAuthDisabled } from '@/auth/devBypass';

/**
 * Ruta raiz (/).
 *
 * Pre-check sincronico via document.cookie (sin request HTTP):
 *  - Dev bypass: ir directo a /dashboard.
 *  - Hay cookie httpOnly auth_token -> ir a /dashboard.
 *  - No hay cookie -> ir a /login.
 *
 * Esto elimina el bug de "Cargando sesion..." porque la decision
 * se toma ANTES de cualquier render o query.
 *
 * Ver docs/AUTH_COOKIE_API.md seccion "Routing (sync detection de sesion)".
 */
export const Route = createFileRoute('/')({
  beforeLoad: () => {
    if (isAuthDisabled()) {
      // eslint-disable-next-line @typescript-eslint/only-throw-error
      throw redirect({ to: '/dashboard' });
    }

    if (typeof document !== 'undefined') {
      const hasCookie = document.cookie
        .split('; ')
        .some((c) => c.startsWith('auth_token='));
      if (hasCookie) {
        // eslint-disable-next-line @typescript-eslint/only-throw-error
        throw redirect({ to: '/dashboard' });
      }
    }

    // eslint-disable-next-line @typescript-eslint/only-throw-error
    throw redirect({ to: '/login' });
  },
});