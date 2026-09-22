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
 * local-first: la API vive en `127.0.0.1:8787`, NO en la nube. Por eso no debe
 * usarse el `networkMode` por defecto ("online") de TanStack Query: cuando
 * Chromium/Windows cree que no hay internet, ese modo PAUSA las queries y las
 * mutaciones (incluido el cobro) sin resolverlas, y el POS parece colgado hasta
 * reiniciar la app.
 *
 * Con `networkMode: 'always'` las queries y mutaciones siempre se intentan
 * contra el backend local y fallan rapido si este no responde.
 *
 * Ademas, en modo local se desactiva `refetchOnReconnect`: al volver internet,
 * TanStack refetchearia TODAS las queries activas de golpe (rafaga de ~20
 * requests) contra el servidor local monohilo, que se satura y congela la UI.
 * El foco de ventana si mantiene refetch para preservar el flujo de cobro.
 *
 * En la web de nube se usa el comportamiento estandar (modo online), porque
 * ahi la API si depende de internet.
 */
export function createAppQueryClient({
  local = isLocalBackend(),
}: { local?: boolean } = {}): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        gcTime: 5 * 60_000,
        refetchOnWindowFocus: true,
        refetchOnReconnect: !local,
        retry: 1,
        ...(local ? { networkMode: 'always' as const } : {}),
      },
      mutations: {
        retry: false,
        ...(local ? { networkMode: 'always' as const } : {}),
      },
    },
  });
}
