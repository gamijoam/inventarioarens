import { createFileRoute, redirect } from '@tanstack/react-router';

import { LoginPage } from '@/auth/LoginPage';

export const Route = createFileRoute('/login')({
  beforeLoad: () => {
    // Si hay sesion persistida (token en localStorage), redirigir al dashboard.
    // NO validamos contra el backend aca: si el token esta expirado, RequireAuth
    // se encargara de limpiar la sesion al hacer /api/auth/me y redirigir de vuelta.
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
      // Si redirect falla por no estar en el contexto del router, ignorar.
      if ((err as { isRedirect?: boolean }).isRedirect) throw err;
    }
  },
  component: LoginPage,
});