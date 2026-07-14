/**
 * ============================================================================
 * BRANDING DEL FRONTEND
 * ----------------------------------------------------------------------------
 * UN solo lugar donde se configura el nombre del sistema y la metadata
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

export const APP_NAME = 'Sistema de Inventario';
export const APP_SHORT_NAME = 'INVENTARIOARENS';
export const APP_TAGLINE = 'Sistema de Inventario multi-tenant';
export const APP_DESCRIPTION =
  'Punto de venta, gestión de productos, traslados, cuentas por cobrar/pagar y sincronización local ↔ nube.';

export const APP_FEATURES = [
  'Venta en mostrador con pagos mixtos USD/VES',
  'Catálogo por cantidad o serializado (IMEI)',
  'Traslados inter-almacén con reserva de stock',
  'Sync bidireccional con outbox + ACK',
] as const;
