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

export type AppMode = 'admin' | 'pos';

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
};

export function resolveAppMode(value: string | undefined): AppMode {
  return value?.trim().toLowerCase() === 'pos' ? 'pos' : 'admin';
}

export function getAppDefinition(mode: AppMode): AppDefinition {
  return APP_DEFINITIONS[mode];
}

export function isRouteAllowedForAppMode(mode: AppMode, pathname: string): boolean {
  if (mode === 'admin') return true;
  return pathname === '/pos' || pathname.startsWith('/pos/');
}

export const APP_MODE = resolveAppMode(import.meta.env.VITE_APP_MODE as string | undefined);
export const APP_DEFINITION = getAppDefinition(APP_MODE);
export const APP_NAME = APP_DEFINITION.name;
export const APP_SHORT_NAME = APP_DEFINITION.shortName;
export const APP_TAGLINE = APP_DEFINITION.tagline;
export const APP_DESCRIPTION = APP_DEFINITION.description;

export const APP_FEATURES = [
  'Venta en mostrador con pagos mixtos USD/VES',
  'Catálogo por cantidad o serializado (IMEI)',
  'Traslados inter-almacén con reserva de stock',
  'Sync bidireccional con outbox + ACK',
] as const;
