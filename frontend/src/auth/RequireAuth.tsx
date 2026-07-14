import { type ReactNode } from 'react';
import { useRouter } from '@tanstack/react-router';

import { useAuth } from './useAuth';
import { Spinner } from '@/components/ui/Spinner';

interface RequireAuthProps {
  children: ReactNode;
}

/**
 * Guard: si no hay sesion activa, redirige a /login guardando la ruta intentada.
 * Mientras carga la sesion persistida, muestra un spinner.
 */
export function RequireAuth({ children }: RequireAuthProps) {
  const { isAuthenticated, isReady } = useAuth();
  const router = useRouter();

  if (!isReady) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <Spinner size="lg" label="Cargando sesión..." />
      </div>
    );
  }

  if (!isAuthenticated) {
    // Redirigir fuera del render (usando navigate imperativo).
    void router.navigate({ to: '/login' });
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <Spinner size="lg" label="Redirigiendo al login..." />
      </div>
    );
  }

  return <>{children}</>;
}