import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider, createRouter } from '@tanstack/react-router';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'sonner';

import { ThemeProvider } from '@/components/layout/ThemeProvider';
import { APP_MODE, APP_SHORT_NAME } from '@/config/branding';
import { routeTree } from './routeTree.gen';
import { registerUnauthorizedHandler } from '@/api/client';
import { applyPosViewport, enablePosTouchMode } from '@/features/pos/touchSupport';

import '@/styles/globals.css';

// Seteamos el <title> de la pestana con la marca corporativa.
document.title = `SDI · ${APP_SHORT_NAME}`;
document.documentElement.dataset.appMode = APP_MODE;

// El cliente POS se usa en tablets: desactivamos el zoom por touch y
// aplicamos touch-action: manipulation. El tap tactil de botones dentro de
// contenedores con scroll lo maneja touchTapHandlers (onTouchEnd nativo).
if (APP_MODE === 'pos') {
  applyPosViewport();
  enablePosTouchMode();
}

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      gcTime: 5 * 60_000,
      refetchOnWindowFocus: true,
      refetchOnReconnect: true,
      retry: 1,
    },
    mutations: {
      retry: false,
    },
  },
});

const router = createRouter({
  routeTree,
  defaultPreload: 'intent',
  context: { queryClient },
  defaultPreloadStaleTime: 0,
  defaultErrorComponent: ({ error }) => {
    const msg = error instanceof Error ? error.message : String(error);
    if (
      msg.includes('dynamically imported') ||
      msg.includes('token') ||
      msg.includes('Failed to fetch') ||
      msg.includes('appendChild')
    ) {
      if (typeof window !== 'undefined') {
        window.location.reload();
      }
      return null;
    }
    return (
      <div className="bg-bg flex min-h-screen flex-col items-center justify-center p-4 text-center">
        <h2 className="text-text-primary text-base font-semibold">Ocurrió un error al cargar la vista</h2>
        <p className="text-text-muted mt-1 text-sm max-w-md">{msg}</p>
        <button
          type="button"
          onClick={() => window.location.reload()}
          className="bg-primary text-primary-foreground hover:bg-primary/90 mt-4 rounded-md px-4 py-2 text-sm font-medium transition-colors"
        >
          Recargar página
        </button>
      </div>
    );
  },
});

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router;
  }
}

// Auto-recarga limpia si el navegador intenta cargar un chunk viejo tras un nuevo despliegue.
if (typeof window !== 'undefined') {
  window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault();
    window.location.reload();
  });
}

// Registrar handler de 401 que navega via SPA (no window.location.href).
// window.location.href causa full reload que pierde el cache de TanStack Query.
// Aqui es donde tenemos acceso al router context.
registerUnauthorizedHandler(() => {
  const loginRoute = window.location.pathname.startsWith('/master') ? '/master/login' : '/login';

  // Solo navegar si no estamos ya en el login correcto (evitar loops en errores de /me).
  if (window.location.pathname !== loginRoute) {
    void router.navigate({ to: loginRoute });
  }
});

const rootEl = document.getElementById('root');
if (!rootEl) throw new Error('Elemento #root no encontrado en el DOM.');

createRoot(rootEl).render(
  <StrictMode>
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
        <Toaster richColors position="top-right" closeButton />
      </QueryClientProvider>
    </ThemeProvider>
  </StrictMode>,
);
