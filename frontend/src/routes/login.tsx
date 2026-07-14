import { createFileRoute, redirect } from '@tanstack/react-router';

import { LoginPage } from '@/auth/LoginPage';
import { isAuthDisabled } from '@/auth/devBypass';

/**
 * Ruta /login.
 *
 * Pre-check: si ya hay cookie httpOnly de sesion activa, redirigir al
 * dashboard inmediatamente (sin pedir credenciales). Esto cubre el caso
 * edge donde el usuario tiene cookie pero el localStorage esta vacio.
 *
 * En dev bypass, permite siempre ir a /login (porque no hay cookie).
 *
 * Ver docs/AUTH_COOKIE_API.md.
 */
export const Route = createFileRoute('/login')({
  beforeLoad: () => {
    if (isAuthDisabled()) {
      // No redirigir: en bypass queremos ver la pagina de login tambien.
      return;
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
  },
  component: LoginPage,
});