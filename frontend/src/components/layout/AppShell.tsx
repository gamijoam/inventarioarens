import { type ReactNode } from 'react';
import { useRouterState } from '@tanstack/react-router';
import { cn } from '@/lib/cn';
import { useSessionStore } from '@/stores/session';
import { useTenantFavicon } from '@/lib/useTenantFavicon';
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
  const tenant = useSessionStore((s) => s.tenant);
  useTenantFavicon(tenant?.logo_url);

  const pathname = useRouterState({ select: (state) => state.location.pathname });
  const isFullBleed = pathname === '/pos' || pathname.startsWith('/pos/');
  const isWide = pathname === '/commissions';

  if (isFullBleed) {
    return <>{children}</>;
  }

  return (
    <div className="bg-bg flex min-h-screen">
      <Sidebar />
      <div className="flex flex-1 flex-col overflow-hidden">
        <Topbar />
        <main className="flex-1 overflow-auto bg-warm-ambient custom-scrollbar">
          <div
            className={cn(
              'mx-auto w-full px-4 py-6 sm:px-6 lg:px-8',
              isWide ? 'max-w-none' : 'max-w-7xl',
            )}
          >
            {children}
          </div>
        </main>
      </div>
    </div>
  );
}
