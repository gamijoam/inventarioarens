import { createFileRoute, redirect } from '@tanstack/react-router';

export const Route = createFileRoute('/')({
  beforeLoad: () => {
    // Bypass de dev: enviar directo al dashboard.
    const enforce = localStorage.getItem('dev_enforce_auth') === '1';
    const bypass =
      import.meta.env.VITE_AUTH_DISABLED === 'true' ||
      (import.meta.env.DEV && !enforce) ||
      localStorage.getItem('dev_skip_auth') === '1';

    if (bypass) {
      // eslint-disable-next-line @typescript-eslint/only-throw-error
      throw redirect({ to: '/dashboard' });
    }

    try {
      const raw = localStorage.getItem('inventory_session');
      if (raw) {
        const parsed = JSON.parse(raw) as { state?: { token?: string } };
        if (parsed?.state?.token) {
          // eslint-disable-next-line @typescript-eslint/only-throw-error
          throw redirect({ to: '/dashboard' });
        }
      }
    } catch (err) {
      if ((err as { isRedirect?: boolean }).isRedirect) throw err;
    }
    // eslint-disable-next-line @typescript-eslint/only-throw-error
    throw redirect({ to: '/login' });
  },
});