import { QueryClient } from '@tanstack/react-query';

/**
 * `true` cuando la app corre contra el backend local (Electron / Motor Local),
 * que se sirve desde `http://127.0.0.1:<puerto>` o `localhost`. En la web de
 * nube el hostname es el dominio y la API se consume relativa (`/api`).
 */
export function isLocalBackend(): boolean {
  return (
    typeof window !== 'undefined' &&
    (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')
  );
}

/**
 * Cliente de datos de la SPA.
 *
 * Para el Electron del Motor Local (backend loopback) usamos politica
 * local-first: la API vive en `127.0.0.1:8787`, no en la nube. Por eso NO debe
 * usarse el `networkMode` por defecto ("online") de TanStack Query: cuando
 * Chromium cree que no hay internet (wifi inestable, sin salida a internet)
 * pausa las mutaciones sin resolverlas y la venta queda "pegada". Con
 * `networkMode: 'always'` las operaciones siempre se intentan contra el backend
 * local y fallan rapido si el backend no responde. Ademas desactivamos
 * `refetchOnReconnect` y `refetchOnWindowFocus` para no disparar una rafaga de
 * requests contra el unico worker local al volver la red o recuperar el foco.
 *
 * En la web de nube se conserva el comportamiento anterior (modo online y
 * refetch al reconectar/enfocar), porque ahi la API si depende de internet.
 */
export function createAppQueryClient({ local = isLocalBackend() }: { local?: boolean } = {}): QueryClient {
  if (local) {
    return new QueryClient({
      defaultOptions: {
        queries: {
          staleTime: 30_000,
          gcTime: 5 * 60_000,
          refetchOnWindowFocus: false,
          refetchOnReconnect: false,
          retry: 1,
          networkMode: 'always',
        },
        mutations: {
          retry: false,
          networkMode: 'always',
        },
      },
    });
  }

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
