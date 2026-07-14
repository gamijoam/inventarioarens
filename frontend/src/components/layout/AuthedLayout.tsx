import { type ReactNode } from 'react';
import { Outlet } from '@tanstack/react-router';

import { AppShell } from './AppShell';

interface AuthedLayoutProps {
  children?: ReactNode;
}

/** Layout raiz para todas las rutas autenticadas. */
export function AuthedLayout({ children }: AuthedLayoutProps) {
  return (
    <AppShell>
      {children ?? <Outlet />}
    </AppShell>
  );
}