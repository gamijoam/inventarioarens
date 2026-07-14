import { createFileRoute, redirect } from '@tanstack/react-router';

export const Route = createFileRoute('/')({
  beforeLoad: () => {
    // Bypass activo por defecto. Para forzar el flujo de login real:
    //   localStorage.setItem('dev_enforce_auth', '1')
    const enforce = localStorage.getItem('dev_enforce_auth') === '1';
    const bypass =
      import.meta.env.VITE_AUTH_DISABLED === 'true' || !enforce;

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