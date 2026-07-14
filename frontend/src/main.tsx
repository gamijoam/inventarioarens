import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider, createRouter } from '@tanstack/react-router';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'sonner';

import { ThemeProvider } from '@/components/layout/ThemeProvider';
import { APP_NAME } from '@/config/branding';
import { routeTree } from './routeTree.gen';
import { registerUnauthorizedHandler } from '@/api/client';

import '@/styles/globals.css';

// Seteamos el <title> de la pestana con el nombre del branding.
document.title = APP_NAME;

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      gcTime: 5 * 60_000,
      refetchOnWindowFocus: false,
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
});

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router;
  }
}

// Registrar handler de 401 que navega via SPA (no window.location.href).
// window.location.href causa full reload que pierde el cache de TanStack Query.
// Aqui es donde tenemos acceso al router context.
registerUnauthorizedHandler(() => {
  // Solo navegar si no estamos ya en /login (evitar loops en errores de /me).
  if (window.location.pathname !== '/login') {
    void router.navigate({ to: '/login' });
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