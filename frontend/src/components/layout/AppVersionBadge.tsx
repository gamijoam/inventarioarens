import { APP_VERSION } from '@/config/branding';

/**
 * Badge de version de la aplicacion, inyectada al compilar desde
 * `frontend/package.json` (vite `define` -> import.meta.env.VITE_APP_VERSION).
 *
 * Como la version se incrusta en el bundle en tiempo de build, la version
 * mostrada SIEMPRE coincide con el bundle que se esta ejecutando (la
 * instalada). No hay riesgo de leer un valor viejo de cache/backend.
 */
export function AppVersionBadge({ className }: { className?: string }) {
  return (
    <span
      data-testid="app-version-badge"
      title={`Version ${APP_VERSION}`}
      className={`text-text-muted inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium ${className ?? ''}`}
    >
      v{APP_VERSION}
    </span>
  );
}
