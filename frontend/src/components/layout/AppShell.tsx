import { type ReactNode } from 'react';
import { useRouterState } from '@tanstack/react-router';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';

interface AppShellProps {
  children: ReactNode;
}

/**
 * Layout principal de la app autenticada.
 * Sidebar colapsable a la izquierda + topbar arriba + contenido.
 */
export function AppShell({ children }: AppShellProps) {
  const pathname = useRouterState({ select: (state) => state.location.pathname });
  const isFullBleed = pathname === '/pos';

  return (
    <div className="flex min-h-screen bg-bg">
      <Sidebar />
      <div className="flex flex-1 flex-col overflow-hidden">
        <Topbar />
        <main className="flex-1 overflow-auto">
          <div className={isFullBleed ? 'w-full' : 'mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8'}>
            {children}
          </div>
        </main>
      </div>
    </div>
  );
}
