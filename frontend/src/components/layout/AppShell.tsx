import { type ReactNode, useEffect } from 'react';
import { useRouterState } from '@tanstack/react-router';
import { cn } from '@/lib/cn';
import { useSessionStore } from '@/stores/session';
import { useTenantFavicon } from '@/lib/useTenantFavicon';
import { useUiPreferences } from '@/features/company-settings/api';
import {
  saveStoredProductFormVisibility,
  type ProductFormVisibility,
} from '@/features/inventory-center/productFormConfig';
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
  const tenant = useSessionStore((state) => state.tenant);
  useTenantFavicon(tenant?.logo_url);

  const { data: uiPreferences } = useUiPreferences();

  useEffect(() => {
    if (uiPreferences?.product_form_visibility) {
      saveStoredProductFormVisibility(
        uiPreferences.product_form_visibility as unknown as ProductFormVisibility,
        tenant?.id,
      );
    }
  }, [uiPreferences?.product_form_visibility, tenant?.id]);

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
        <main className="flex-1 overflow-auto">
          <div className="mx-auto w-full max-w-none px-4 py-6 sm:px-6 lg:px-8 2xl:px-10">
            {children}
          </div>
        </main>
      </div>
    </div>
  );
}
