import { QueryClient } from '@tanstack/react-query';

/**
 * Cliente de datos de la SPA.
 *
 * NOTA (2026-09-22): se intentó pasar a modo "local-first"
 * (`networkMode: 'always'`) para el Electron del Motor Local, pero introdujo una
 * regresión en el cobro del POS (no procesaba ventas), asi que se revirtió a la
 * configuración estándar. Si se retoma, hacerlo detrás de pruebas que cubran el
 * flujo de cobro (checkout normal y pago de orden pendiente) de punta a punta.
 */
export function createAppQueryClient(): QueryClient {
  return new QueryClient({
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
}
