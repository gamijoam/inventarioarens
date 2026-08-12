/**
 * ============================================================================
 * BRANDING DEL FRONTEND
 * ----------------------------------------------------------------------------
 * Un solo lugar donde se configura el nombre del sistema y la metadata
 * visible al usuario. Cambiar APP_NAME aqui actualiza:
 *   - El <title> de la pestana del navegador.
 *   - El branding del panel izquierdo en la pantalla de login.
 *   - El nombre en el sidebar.
 *   - El sub-titulo de la aplicacion.
 *
 * Si mas adelante se necesita i18n, mover esto a un provider de i18n.
 * Por ahora es estatico y un solo idioma.
 * ============================================================================
 */

export type AppMode = 'admin' | 'pos' | 'technician';

export interface AppDefinition {
  mode: AppMode;
  name: string;
  shortName: string;
  tagline: string;
  description: string;
}

export const APP_DEFINITIONS: Record<AppMode, AppDefinition> = {
  admin: {
    mode: 'admin',
    name: 'Sistema de Inventario (Administrativo)',
    shortName: 'Sistema de Inventario',
    tagline: 'Administración multi-tenant',
    description:
      'Productos, inventario, compras, ventas, caja, permisos, reportes y sincronización local ↔ nube.',
  },
  pos: {
    mode: 'pos',
    name: 'POS',
    shortName: 'POS',
    tagline: 'Punto de venta',
    description: 'Ventas, pagos, caja, recibos y operación local con sincronización segura.',
  },
  technician: {
    mode: 'technician',
    name: 'Soporte Técnico',
    shortName: 'Soporte Técnico',
    tagline: 'Diagnóstico y sincronización local',
    description:
      'Vinculación de empresas, workers, sincronización y diagnóstico de la instalación local.',
  },
};

export function resolveAppMode(value: string | undefined): AppMode {
  const normalized = value?.trim().toLowerCase();
  return normalized === 'pos' || normalized === 'technician' ? normalized : 'admin';
}

export function getAppDefinition(mode: AppMode): AppDefinition {
  return APP_DEFINITIONS[mode];
}

export function isRouteAllowedForAppMode(mode: AppMode, pathname: string): boolean {
  if (mode === 'admin') return true;
  if (mode === 'technician') return pathname === '/support' || pathname.startsWith('/support/');
  return pathname === '/pos' || pathname.startsWith('/pos/');
}

export const APP_MODE = resolveAppMode(import.meta.env.VITE_APP_MODE as string | undefined);
export const APP_DEFINITION = getAppDefinition(APP_MODE);
export const APP_NAME = APP_DEFINITION.name;
export const APP_SHORT_NAME = APP_DEFINITION.shortName;
export const APP_TAGLINE = APP_DEFINITION.tagline;
export const APP_DESCRIPTION = APP_DEFINITION.description;

/**
 * Version de la aplicacion, inyectada al compilar por vite (define) desde
 * `frontend/package.json`. Dev siempre cae en un valor de desarrollo; en
 * los builds de release contiene la version exacta del bundle.
 */
export const APP_VERSION = (import.meta.env.VITE_APP_VERSION as string | undefined) ?? 'dev';

export const APP_FEATURES = [
  'Venta en mostrador con pagos mixtos USD/VES',
  'Catálogo por cantidad o serializado (IMEI)',
  'Traslados inter-almacén con reserva de stock',
  'Sync bidireccional con outbox + ACK',
] as const;
