/**
 * Cliente Laravel Echo + Pusher para notificaciones WebSocket push.
 *
 * Inicializa Echo solo una vez (singleton) y solo en el navegador.
 * Reverb usa el protocolo Pusher-compatible, asi que podemos usar el
 * `pusher-js` y `laravel-echo` oficiales sin tocar el backend Laravel.
 *
 * Para produccion, sobrescribir las vars VITE_REVERB_* via .env o el
 * build process. Los defaults en vite.config.ts sirven para dev local.
 *
 * Si Reverb NO esta disponible (servidor caido), Echo simplemente no
 * se conecta y los hooks que lo usan caerian al polling como fallback
 * (ver useTransferRequestArrivalNotification).
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Echo?: Echo<'reverb'>;
    Pusher?: typeof Pusher;
  }
}

let initialized = false;

export function initEcho(): Echo<'reverb'> | null {
  if (typeof window === 'undefined') {
    return null;
  }

  if (initialized && window.Echo) {
    return window.Echo;
  }

  // Las vars se inyectan por Vite via define en vite.config.ts (defaults
  // locales) o via .env real en el build de produccion.
  const key = (import.meta as unknown as { env?: Record<string, string> }).env?.VITE_REVERB_APP_KEY
    ?? 'inventarioarens-key';
  const host = (import.meta as unknown as { env?: Record<string, string> }).env?.VITE_REVERB_HOST
    ?? 'localhost';
  const portStr = (import.meta as unknown as { env?: Record<string, string> }).env?.VITE_REVERB_PORT
    ?? '8081';
  const scheme = (import.meta as unknown as { env?: Record<string, string> }).env?.VITE_REVERB_SCHEME
    ?? 'http';
  const port = Number.parseInt(portStr, 10);

  if (!key || Number.isNaN(port)) {
    return null;
  }

  // Pusher requiere estar en window para que el Echo lo encuentre.
  window.Pusher ??= Pusher;

  const echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
  });

  window.Echo = echo;
  initialized = true;
  return echo;
}
