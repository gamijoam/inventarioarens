import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export interface SimpleModeItemConfig {
  to: string;
  label: string;
  description: string;
  defaultVisible: boolean;
}

export const AVAILABLE_SIMPLE_MODE_ITEMS: SimpleModeItemConfig[] = [
  { to: '/dashboard', label: 'Tablero', description: 'Métricas clave y resumen de ventas del día', defaultVisible: true },
  { to: '/inventory', label: 'Inventario', description: 'Catálogo de productos, stock, precios y marcas', defaultVisible: true },
  { to: '/pos', label: 'Punto de Venta (POS)', description: 'Facturación rápida, cobro y apertura/cierre de caja', defaultVisible: true },
  { to: '/customers', label: 'Clientes', description: 'Directorio de clientes y límites de crédito', defaultVisible: true },
  { to: '/suppliers', label: 'Proveedores', description: 'Gestión y directorio de proveedores', defaultVisible: false },
  { to: '/sales', label: 'Ventas', description: 'Historial detallado de órdenes, devoluciones y facturas', defaultVisible: false },
  { to: '/purchases', label: 'Compras', description: 'Órdenes de compra y recepción de mercancía', defaultVisible: false },
  { to: '/reports', label: 'Reportes', description: 'Reportes de ventas, caja y cierre de turno', defaultVisible: true },
  { to: '/receivables', label: 'Cuentas por Cobrar', description: 'Saldos pendientes de clientes y cobros', defaultVisible: false },
  { to: '/payables', label: 'Cuentas por Pagar', description: 'Deudas con proveedores y programación de pagos', defaultVisible: false },
  { to: '/transfers', label: 'Traslados', description: 'Movimientos de inventario entre almacenes', defaultVisible: false },
  { to: '/inventory-transfer-requests', label: 'Solicitudes inter-empresa', description: 'Transferencias entre diferentes empresas del grupo', defaultVisible: false },
  { to: '/quotations', label: 'Cotizaciones', description: 'Emisión de presupuestos para clientes', defaultVisible: false },
  { to: '/warranties', label: 'Garantías', description: 'Recepción y seguimiento de garantías de repuestos', defaultVisible: false },
  { to: '/workshop', label: 'Taller', description: 'Órdenes de servicio y reparación mecánica', defaultVisible: false },
  { to: '/users', label: 'Usuarios y Acceso', description: 'Gestión de usuarios, roles y permisos', defaultVisible: true },
  { to: '/settings/company', label: 'Configuración', description: 'Datos de empresa, modo fácil y opciones generales', defaultVisible: true },
];

export const DEFAULT_VISIBLE_ROUTES: string[] = AVAILABLE_SIMPLE_MODE_ITEMS
  .filter((i) => i.defaultVisible)
  .map((i) => i.to);

interface UiModeState {
  isSimpleMode: boolean;
  visibleRoutes: string[];
  toggleSimpleMode: () => void;
  setSimpleMode: (simple: boolean) => void;
  toggleRoute: (route: string) => void;
  setVisibleRoutes: (routes: string[]) => void;
  resetToDefaults: () => void;
}

export const useUiModeStore = create<UiModeState>()(
  persist(
    (set) => ({
      isSimpleMode: true, // Modo Fácil activo por defecto para Repuestos Avilacar
      visibleRoutes: DEFAULT_VISIBLE_ROUTES,
      toggleSimpleMode: () => set((state) => ({ isSimpleMode: !state.isSimpleMode })),
      setSimpleMode: (simple: boolean) => set({ isSimpleMode: simple }),
      toggleRoute: (route: string) =>
        set((state) => {
          const exists = state.visibleRoutes.includes(route);
          return {
            visibleRoutes: exists
              ? state.visibleRoutes.filter((r) => r !== route)
              : [...state.visibleRoutes, route],
          };
        }),
      setVisibleRoutes: (routes: string[]) => set({ visibleRoutes: routes }),
      resetToDefaults: () =>
        set({
          isSimpleMode: true,
          visibleRoutes: DEFAULT_VISIBLE_ROUTES,
        }),
    }),
    {
      name: 'sdi-ui-mode',
    },
  ),
);
